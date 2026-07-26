<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\MeetingProviderInterface;
use App\Booking\DTOs\MeetingCreationContext;
use App\Booking\DTOs\MeetingCreationResult;
use App\Booking\DTOs\MeetingUpdateContext;
use App\Booking\Enums\BookingActivityAction;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingLocationType;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\MeetingJoinAvailability;
use App\Booking\Enums\MeetingStatus;
use App\Booking\Events\MeetingCreated;
use App\Booking\Events\MeetingUpdated;
use App\Booking\Exceptions\BookingException;
use App\Booking\Jobs\CaptureLessonRecordingJob;
use App\Booking\Meetings\ManualMeetingProvider;
use App\Exceptions\Student\StudentActionNotAvailableException;
use App\Lessons\Enums\LessonStatus;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\Student\StudentLifecycleService;
use App\Settings\MeetingSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates and stores meeting details for confirmed bookings in the
 * dedicated `booking_meetings` table (one row per booking, enforced by
 * a unique constraint on booking_id).
 *
 * Eligibility (all must hold):
 *   - booking.status === Confirmed
 *   - starts_at/ends_at exist
 *   - booking.location_type === Online ("booking type supports an
 *     online lesson", reusing the existing per-booking field rather
 *     than adding a new one)
 *   - payment_status is Paid (paid types, gated by
 *     MeetingSettings::create_after_paid_booking_confirmation) or
 *     NotRequired (demo/free types, gated by
 *     create_after_demo_booking_confirmation)
 *
 * `meetings_enabled = false` is the platform-wide off switch: on the
 * automatic (listener-triggered) path this is a silent no-op, not a
 * failure. Any other resolution failure (misconfigured provider, an
 * explicit admin provider choice, or an exception mid-create/update)
 * is a genuine failure and is recorded as meeting_status = Failed,
 * logged via AuditTrailService, and safe to retry.
 */
final class BookingMeetingService implements BookingMeetingServiceInterface
{
    public function __construct(
        private readonly BookingRepositoryInterface $bookings,
        private readonly MeetingProviderResolver $providers,
        private readonly MeetingSettings $settings,
        private readonly AuditTrailService $audit,
        private readonly StudentLifecycleService $studentLifecycle,
        private readonly RecordingService $recordings,
    ) {}

    public function createMeeting(Booking $booking, ?string $providerKey = null): ?BookingMeeting
    {
        $existing = $this->findForBooking($booking);

        if ($existing?->status === MeetingStatus::Created) {
            return $existing;
        }

        if (! $this->isEligible($booking)) {
            return $existing;
        }

        $key = $providerKey ?? $this->settings->default_provider;

        try {
            $provider = $providerKey !== null ? $this->providers->resolve($key) : $this->providers->current();
        } catch (BookingException $e) {
            if (! $this->settings->meetings_enabled && $providerKey === null) {
                // Deliberate platform-wide off switch on the automatic path — no failure noise.
                return $existing;
            }

            return $this->persistFailure($booking, $key, $e->getMessage());
        }

        $previousStatus = $existing?->status;
        $previousJoinUrl = $existing?->join_url;

        $meeting = DB::transaction(function () use ($booking, $existing, $provider): BookingMeeting {
            try {
                $context = new MeetingCreationContext(requestedBy: Auth::id());
                // Same-provider retry updates the provider-side resource;
                // a cross-provider retry (e.g. Google failed → admin picks
                // Zoom) must create fresh — the old row's provider ids
                // mean nothing to the new provider.
                $result = $existing !== null && $existing->provider === $provider->key()
                    ? $provider->updateMeeting($existing, $context->toUpdateContext())
                    : $provider->createMeeting($booking, $context);
            } catch (Throwable $e) {
                return $this->persistFailure($booking, $provider->key(), $e->getMessage());
            }

            return $this->persistResult($booking, $result);
        });

        $this->dispatchTransitionEvents($booking, $meeting, $previousStatus, $previousJoinUrl);

        if ($meeting->status === MeetingStatus::Created) {
            $this->registerRecordingIfEligible($booking, $meeting, $provider);
        }

        return $meeting;
    }

    /**
     * GAP-028 — registration only creates the Recording row (idempotent,
     * cheap); the actual capture is a SEPARATE queued job dispatched
     * afterCommit so a slow/failed provider fetch can never block or
     * fail meeting creation itself. Delayed until
     * recording_capture_delay_minutes after the LESSON ends (not after
     * now) — a lesson booked for tomorrow must not trigger an capture
     * attempt tonight.
     */
    private function registerRecordingIfEligible(Booking $booking, BookingMeeting $meeting, MeetingProviderInterface $provider): void
    {
        $recording = $this->recordings->registerIfEligible($booking, $meeting, $provider);

        if ($recording === null) {
            return;
        }

        CaptureLessonRecordingJob::dispatch($recording->id)
            ->afterCommit()
            ->delay($meeting->ends_at->addMinutes(max(0, $this->settings->recording_capture_delay_minutes)));
    }

    public function saveManualMeeting(Booking $booking, MeetingUpdateContext $context): ?BookingMeeting
    {
        if (! $this->isEligible($booking)) {
            return $this->findForBooking($booking);
        }

        try {
            $provider = $this->providers->resolve(ManualMeetingProvider::KEY);
        } catch (BookingException $e) {
            return $this->persistFailure($booking, ManualMeetingProvider::KEY, $e->getMessage());
        }

        $existing = $this->findForBooking($booking);
        $previousStatus = $existing?->status;
        $previousJoinUrl = $existing?->join_url;

        $meeting = DB::transaction(function () use ($booking, $existing, $provider, $context): BookingMeeting {
            try {
                $result = $existing !== null
                    ? $provider->updateMeeting($existing, $context)
                    : $provider->createMeeting($booking, $context->toCreationContext());
            } catch (Throwable $e) {
                return $this->persistFailure($booking, ManualMeetingProvider::KEY, $e->getMessage());
            }

            return $this->persistResult($booking, $result);
        });

        $this->dispatchTransitionEvents($booking, $meeting, $previousStatus, $previousJoinUrl);

        return $meeting;
    }

    public function cancelMeeting(Booking $booking): ?BookingMeeting
    {
        $meeting = $this->findForBooking($booking);

        if ($meeting === null || $meeting->status === MeetingStatus::Cancelled) {
            return $meeting;
        }

        // Nothing exists on the provider side (creation failed before an
        // event/meeting id was assigned, or the provider is manual-like):
        // mark cancelled directly. Resolving the provider here would only
        // add failure modes — e.g. the admin already disabled it — for a
        // remote deletion that has nothing to delete.
        if ($meeting->provider_event_id === null && $meeting->provider_meeting_id === null) {
            $meeting = $this->upsert($booking, [
                'provider' => $meeting->provider,
                'status' => MeetingStatus::Cancelled,
                'failure_reason' => null,
            ]);

            $this->syncLegacyBookingColumns($booking, $meeting);

            return $meeting;
        }

        try {
            $provider = $this->providers->resolve($meeting->provider);
            $result = $provider->cancelMeeting($meeting);
        } catch (Throwable $e) {
            return $this->persistCancellationFailure($booking, $meeting->provider, $e->getMessage());
        }

        if ($result->status === MeetingStatus::Failed) {
            return $this->persistCancellationFailure(
                $booking,
                $meeting->provider,
                $result->failureReason ?? 'Meeting provider reported a cancellation failure.',
            );
        }

        $meeting = $this->upsert($booking, [
            'provider' => $meeting->provider,
            'status' => $result->status,
            'failure_reason' => $result->failureReason,
        ]);

        $this->syncLegacyBookingColumns($booking, $meeting);

        $this->audit->logSystem(
            'bookings',
            'meeting_cancelled',
            sprintf('Meeting cancelled for booking %s.', $booking->reference),
            $booking,
            ['provider' => $meeting->provider],
        );

        return $meeting;
    }

    public function isEligible(Booking $booking): bool
    {
        if ($booking->status !== BookingStatus::Confirmed) {
            return false;
        }

        if ($booking->starts_at === null || $booking->ends_at === null) {
            return false;
        }

        if ($booking->location_type !== BookingLocationType::Online) {
            return false;
        }

        return match ($booking->payment_status) {
            BookingPaymentStatus::NotRequired => $this->settings->create_after_demo_booking_confirmation,
            BookingPaymentStatus::Paid => $this->settings->create_after_paid_booking_confirmation,
            default => false,
        };
    }

    public function findForBooking(Booking $booking): ?BookingMeeting
    {
        return BookingMeeting::query()->where('booking_id', $booking->id)->first();
    }

    public function joinAvailabilityFor(Booking $booking, bool $roleVisible, ?LessonStatus $lessonStatus = null): MeetingJoinAvailability
    {
        if (! $roleVisible || $booking->status !== BookingStatus::Confirmed) {
            return MeetingJoinAvailability::Unavailable;
        }

        if ($lessonStatus !== null && ! $lessonStatus->isOpen()) {
            return MeetingJoinAvailability::Unavailable;
        }

        // Relation read only — callers iterating a list must eager-load
        // `meeting` themselves; this never issues a query of its own.
        $meeting = $booking->meeting;

        if ($meeting === null || $meeting->status !== MeetingStatus::Created || blank($meeting->join_url)) {
            return MeetingJoinAvailability::NotReady;
        }

        $windowStartsAt = ($meeting->starts_at ?? $booking->starts_at)->subMinutes($this->settings->meeting_link_visible_before_minutes);
        $windowEndsAt = ($meeting->ends_at ?? $booking->ends_at)->addMinutes($this->settings->meeting_link_visible_after_minutes);

        if (now()->lt($windowStartsAt)) {
            return MeetingJoinAvailability::TooEarly;
        }

        if (now()->gt($windowEndsAt)) {
            return MeetingJoinAvailability::Unavailable;
        }

        return MeetingJoinAvailability::Available;
    }

    public function joinUrlFor(Booking $booking, bool $roleVisible, ?LessonStatus $lessonStatus = null): ?string
    {
        return $this->joinAvailabilityFor($booking, $roleVisible, $lessonStatus) === MeetingJoinAvailability::Available
            ? $booking->meeting?->join_url
            : null;
    }

    /**
     * Phase 24H.2A/24H.2B — GAP-013: THE complete authoritative student
     * meeting-URL disclosure decision. A meeting URL is a sensitive
     * access credential; every student-facing surface (BookingHistory,
     * the student dashboard, StudentBookingResource, and the meeting
     * notifications at send time) must obtain it through here, never by
     * reading meeting->join_url directly, so neither lifecycle nor
     * time-window enforcement can be bypassed by a future UI/API caller.
     *
     * Returns the URL only when ALL of:
     *  - the viewer IS the booking's student (ownership is the actor
     *    evidence — a dual-role instructor viewing someone else's
     *    booking is simply a non-owner here, and the instructor path
     *    stays joinAvailabilityFor(), untouched);
     *  - the viewer passes the central strict lifecycle guard
     *    (student role + student_status === Active, fresh read —
     *    Registered/Suspended/Archived/null/missing-profile all fail
     *    closed with no state disclosure, surfaced as a plain null);
     *  - joinUrlFor()/joinAvailabilityFor() — the ONE existing window
     *    calculation, never duplicated here — resolves Available under
     *    the student visibility setting: booking Confirmed, meeting
     *    Created with a non-blank URL, and the current server time
     *    inside [starts_at - meeting_link_visible_before_minutes,
     *    ends_at + meeting_link_visible_after_minutes] (both boundary
     *    instants inclusive; zero values collapse the window to exactly
     *    [starts_at, ends_at]; all instants compared absolutely, so
     *    display timezones never shift the window).
     */
    public function studentJoinUrlFor(Booking $booking, ?User $viewer): ?string
    {
        if ($viewer === null || $booking->student_id !== $viewer->id) {
            return null;
        }

        try {
            $this->studentLifecycle->assertEligibleForStudentAction($viewer);
        } catch (StudentActionNotAvailableException) {
            return null;
        }

        return $this->joinUrlFor($booking, $this->settings->student_join_url_visible);
    }

    /**
     * Participant-facing lifecycle events, fired after the write
     * transaction and only on genuine transitions: entering Created
     * fires MeetingCreated; an already-created meeting whose join URL
     * actually changed fires MeetingUpdated; a re-save with no real
     * change (or a failure) fires nothing here. Admin-facing failure
     * notifications flow separately through the Activity Log pipeline
     * (persistFailure's audit entry → NotificationMapper).
     */
    private function dispatchTransitionEvents(
        Booking $booking,
        BookingMeeting $meeting,
        ?MeetingStatus $previousStatus,
        ?string $previousJoinUrl,
    ): void {
        if ($meeting->status !== MeetingStatus::Created) {
            return;
        }

        if ($previousStatus !== MeetingStatus::Created) {
            MeetingCreated::dispatch($booking, $meeting);

            return;
        }

        if ($meeting->join_url !== $previousJoinUrl) {
            MeetingUpdated::dispatch($booking, $meeting);
        }
    }

    private function persistResult(Booking $booking, MeetingCreationResult $result): BookingMeeting
    {
        $failureReason = $result->status === MeetingStatus::Failed
            ? 'Meeting provider reported a conference creation failure.'
            : null;

        $meeting = $this->upsert($booking, [
            'provider' => $result->provider,
            // Kept even on failure: Google's async conference can fail on
            // an event that was inserted successfully — the retry path
            // must update that event, not insert a duplicate.
            'provider_meeting_id' => $result->providerMeetingId,
            'provider_event_id' => $result->providerEventId,
            'join_url' => $result->joinUrl,
            'host_url' => $result->hostUrl,
            'password' => $result->password,
            'starts_at' => $result->startsAt,
            'ends_at' => $result->endsAt,
            'timezone' => $result->timezone,
            'status' => $result->status,
            'failure_reason' => $failureReason,
            'metadata' => $result->metadata,
        ]);

        $this->syncLegacyBookingColumns($booking, $meeting);

        // A provider can report failure as a *result* (Google's async
        // conference creation resolving to status "failure") rather than
        // an exception — still a creation failure, and it must leave the
        // same audit/notification trail as the exception path.
        if ($result->status === MeetingStatus::Failed) {
            $this->audit->logSystem(
                'bookings',
                'meeting_creation_failed',
                sprintf('Meeting creation failed for booking %s: %s', $booking->reference, $failureReason),
                $booking,
                ['provider' => $result->provider, 'reason' => $failureReason],
            );

            return $meeting;
        }

        if ($result->status === MeetingStatus::Created) {
            $this->bookings->logActivity(
                $booking,
                BookingActivityAction::MeetingLinked,
                BookingActor::System,
                meta: ['provider' => $result->provider],
            );

            $this->audit->logSystem(
                'bookings',
                'meeting_created',
                sprintf('Meeting created for booking %s via %s.', $booking->reference, $result->provider),
                $booking,
                ['provider' => $result->provider],
            );
        }

        return $meeting;
    }

    /**
     * A provider-side cancellation that failed leaves a live, joinable
     * meeting behind for a booking that no longer stands — always
     * audited (→ admin notification via NotificationMapper) so someone
     * can clean up the orphaned event manually.
     */
    private function persistCancellationFailure(Booking $booking, string $providerKey, string $reason): BookingMeeting
    {
        $meeting = $this->upsert($booking, [
            'provider' => $providerKey,
            'status' => MeetingStatus::Failed,
            'failure_reason' => Str::limit($reason, 500),
        ]);

        $this->syncLegacyBookingColumns($booking, $meeting);

        $this->audit->logSystem(
            'bookings',
            'meeting_cancellation_failed',
            sprintf('Meeting cancellation failed for booking %s: %s', $booking->reference, $reason),
            $booking,
            ['provider' => $providerKey, 'reason' => $reason],
        );

        return $meeting;
    }

    private function persistFailure(Booking $booking, string $providerKey, string $reason): BookingMeeting
    {
        $meeting = $this->upsert($booking, [
            'provider' => $providerKey,
            'status' => MeetingStatus::Failed,
            'failure_reason' => Str::limit($reason, 500),
        ]);

        $this->audit->logSystem(
            'bookings',
            'meeting_creation_failed',
            sprintf('Meeting creation failed for booking %s: %s', $booking->reference, $reason),
            $booking,
            ['provider' => $providerKey, 'reason' => $reason],
        );

        return $meeting;
    }

    /** @param  array<string, mixed>  $attributes */
    private function upsert(Booking $booking, array $attributes): BookingMeeting
    {
        $meeting = BookingMeeting::query()->firstOrNew(['booking_id' => $booking->id]);
        $isNew = ! $meeting->exists;

        $meeting->fill([
            'starts_at' => $booking->starts_at,
            'ends_at' => $booking->ends_at,
            'timezone' => $booking->timezone,
            ...$attributes,
        ]);
        $meeting->updated_by = Auth::id();

        if ($isNew) {
            $meeting->created_by = Auth::id();
        }

        $meeting->save();

        return $meeting;
    }

    /**
     * Keeps the pre-existing bookings.meeting_provider/meeting_ref/
     * meeting_url columns in sync so BookingConfirmedNotification's
     * email CTA (and any other legacy reader) keeps working without
     * being rewritten this phase — booking_meetings is the canonical
     * store; these are a read-only mirror.
     */
    private function syncLegacyBookingColumns(Booking $booking, BookingMeeting $meeting): void
    {
        $booking->forceFill([
            'meeting_provider' => $meeting->provider,
            'meeting_ref' => $meeting->provider_event_id ?? $meeting->provider_meeting_id,
            'meeting_url' => $meeting->status === MeetingStatus::Created ? $meeting->join_url : null,
        ])->save();
    }
}
