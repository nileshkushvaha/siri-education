<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\EndsActiveMeetings;
use App\Booking\Contracts\MeetingProviderInterface;
use App\Booking\Contracts\ReconcilesAmbiguousMeetings;
use App\Booking\DTOs\MeetingCreationContext;
use App\Booking\DTOs\MeetingCreationResult;
use App\Booking\DTOs\MeetingUpdateContext;
use App\Booking\DTOs\StudentJoinState;
use App\Booking\Enums\BookingActivityAction;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingLocationType;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\MeetingJoinAvailability;
use App\Booking\Enums\MeetingStatus;
use App\Booking\Enums\RecordingStatus;
use App\Booking\Events\MeetingCreated;
use App\Booking\Events\MeetingUpdated;
use App\Booking\Exceptions\AmbiguousMeetingCreationException;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\MeetingHostCapacityException;
use App\Booking\Exceptions\MeetingProviderSwitchNotSupportedException;
use App\Booking\Jobs\CaptureLessonRecordingJob;
use App\Booking\Meetings\ManualMeetingProvider;
use App\Enums\InstructorStatus;
use App\Exceptions\Student\StudentActionNotAvailableException;
use App\Lessons\Enums\LessonStatus;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Models\Recording;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\Student\StudentLifecycleService;
use App\Settings\MeetingSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
    /** booking_meetings.metadata flag: a create left the remote state unknown. */
    public const string META_REMOTE_STATE_UNKNOWN = 'remote_state_unknown';

    /** booking_meetings.metadata: what the last reconciliation query established. */
    public const string META_RECONCILIATION = 'reconciliation';

    /** booking_meetings.metadata: how an administrator resolved the ambiguity. */
    public const string META_AMBIGUITY_RESOLUTION = 'ambiguity_resolution';

    /**
     * booking_meetings.metadata: append-only record of everything that
     * happened to an ambiguous create — the unanswered request, every
     * reconciliation query and what it established, and the final
     * resolution (who, why, when). Survives every later write to the row.
     */
    public const string META_AMBIGUITY_HISTORY = 'ambiguity_history';

    /** How far a remote meeting's start may sit from the booking before an adoption with no agenda reference is refused. */
    private const int ADOPTION_TIME_TOLERANCE_HOURS = 24;

    public function __construct(
        private readonly BookingRepositoryInterface $bookings,
        private readonly MeetingProviderResolver $providers,
        private readonly MeetingSettings $settings,
        private readonly AuditTrailService $audit,
        private readonly StudentLifecycleService $studentLifecycle,
        private readonly RecordingService $recordings,
        private readonly MeetingHostCapacityService $hostCapacity,
    ) {}

    public function createMeeting(Booking $booking, ?string $providerKey = null): ?BookingMeeting
    {
        // Cheap pre-checks outside the lock; the locked re-read below is
        // what actually decides.
        $existing = $this->findForBooking($booking);

        if ($existing?->status === MeetingStatus::Created) {
            // An EXPLICIT request for a different provider is refused
            // loudly rather than answered with the untouched existing
            // meeting — an administrator must never read "created" about
            // a switch that did not happen. The automatic path (null
            // provider) stays idempotent and silent, as it must for a
            // redelivered listener.
            if ($providerKey !== null && $providerKey !== $existing->provider) {
                throw MeetingProviderSwitchNotSupportedException::between($booking->reference, $existing->provider, $providerKey);
            }

            return $existing;
        }

        if (! $this->isEligible($booking)) {
            return $existing;
        }

        // One remote create per booking, ever. The unique local row only
        // proves one ROW exists; two callers (a redelivered listener and
        // an admin retry, say) racing past the read above would each ask
        // the provider for a meeting. The lock serializes them so the
        // second re-reads a Created row and stops.
        return $this->bookings->withMeetingCreationLock(
            $booking->id,
            fn (): ?BookingMeeting => $this->createMeetingLocked($booking->fresh(['meeting']), $providerKey),
        );
    }

    private function createMeetingLocked(Booking $booking, ?string $providerKey): ?BookingMeeting
    {
        $existing = $this->findForBooking($booking);

        if ($existing?->status === MeetingStatus::Created) {
            return $existing;
        }

        if ($existing !== null) {
            $this->assertNoCaptureInFlight($booking, $existing);
        }

        // Explicit admin choice > the provider the booking was ACCEPTED
        // for (meeting_provider_intent, pinned when capacity reservation
        // is on) > today's global default. The intent is what stops a
        // later default-provider change from routing an accepted booking
        // onto a Zoom host nobody reserved — or away from one it holds.
        $pinned = $providerKey ?? $booking->meeting_provider_intent;
        $key = $pinned ?? $this->settings->default_provider;

        try {
            $provider = $pinned !== null ? $this->providers->resolve($key) : $this->providers->current();
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
            $context = new MeetingCreationContext(requestedBy: Auth::id());
            $hostId = null;

            // A capacity-governed provider never gets a meeting without a
            // reservation. Normally the booking reserved at acceptance and
            // this only reads it back; a booking accepted before the
            // feature (or a manual admin choice of Zoom) reserves here,
            // and is refused clearly — recorded as a failed meeting, never
            // a silent switch — when no host has room. A meeting that
            // already exists is pinned to the host it lives on.
            if ($this->hostCapacity->appliesTo($provider->key())) {
                try {
                    $reservation = $this->hostCapacity->ensureReserved(
                        $booking,
                        requiredHostId: $existing?->provider === $provider->key() ? $existing->platform_meeting_host_id : null,
                    );
                } catch (MeetingHostCapacityException $e) {
                    return $this->persistFailure($booking, $provider->key(), $e->getMessage());
                }

                $hostId = $reservation->platform_meeting_host_id;
                $context = new MeetingCreationContext(
                    requestedBy: $context->requestedBy,
                    hostReference: $reservation->host->host_reference,
                );
            }

            try {
                // A previous attempt left the remote state UNKNOWN. Ask the
                // provider what it holds. Exactly one remote meeting for
                // this booking is adopted; ANYTHING else fails closed and
                // waits for an operator (meetings:resolve-ambiguous) —
                // "no match" does not prove the original create failed,
                // and several matches cannot be chosen between.
                if ($this->remoteStateUnknown($existing, $provider->key())) {
                    return $this->reconcileAmbiguous($booking, $existing, $provider, $context, $hostId);
                }

                // Same-provider retry updates the provider-side resource;
                // a cross-provider retry (e.g. Google failed → admin picks
                // Zoom) must create fresh — the old row's provider ids
                // mean nothing to the new provider.
                $result = $existing !== null && $existing->provider === $provider->key()
                    ? $provider->updateMeeting($existing, $context->toUpdateContext())
                    : $provider->createMeeting($booking, $context);
            } catch (AmbiguousMeetingCreationException $e) {
                // Zoom MAY hold a meeting we have no id for. Recorded as
                // such: automatic paths do not re-run for a Failed row, and
                // the next explicit attempt reconciles (above) before it
                // creates. Never a blind retry, never a silent duplicate.
                return $this->persistFailure(
                    $booking,
                    $provider->key(),
                    $e->getMessage(),
                    $this->withAmbiguityEvent($existing, [self::META_REMOTE_STATE_UNKNOWN => true], ['event' => 'ambiguous_create', 'reason' => Str::limit($e->getMessage(), 300)]),
                    $hostId,
                );
            } catch (Throwable $e) {
                return $this->persistFailure($booking, $provider->key(), $e->getMessage(), null, $hostId);
            }

            // A create that follows a resolved ambiguity keeps the story of
            // how it got here; the provider's own metadata never replaces it.
            return $this->persistResult($booking, $result, $hostId, $this->carriedAmbiguityRecord($existing));
        });

        $this->dispatchTransitionEvents($booking, $meeting, $previousStatus, $previousJoinUrl);

        if ($meeting->status === MeetingStatus::Created) {
            $this->registerRecordingIfEligible($booking, $meeting, $provider);
        }

        return $meeting;
    }

    /**
     * Replacing a meeting changes which remote meeting the row names. A
     * recording captured for the OLD meeting that is still being
     * transferred, or stored but not yet verified and published, would
     * otherwise be published under the new one. The replacement waits;
     * the capture finishes (Available or Failed) within the job timeout
     * and the operator retries. Publication independently re-checks the
     * meeting identity it captured for, so this guard is the friendly
     * refusal and that check is the guarantee.
     *
     * @throws BookingException
     */
    private function assertNoCaptureInFlight(Booking $booking, BookingMeeting $existing): void
    {
        $inFlight = Recording::query()
            ->where('booking_meeting_id', $existing->getKey())
            ->whereIn('status', [RecordingStatus::Transferring, RecordingStatus::Stored])
            ->exists();

        if ($inFlight) {
            throw new BookingException(sprintf(
                'Booking %s: a recording of the previous meeting is still being captured. Wait for it to finish before creating a replacement meeting.',
                $booking->reference,
            ));
        }
    }

    private function remoteStateUnknown(?BookingMeeting $existing, string $providerKey): bool
    {
        return $existing !== null
            && $existing->provider === $providerKey
            && ($existing->metadata[self::META_REMOTE_STATE_UNKNOWN] ?? false) === true;
    }

    /**
     * What the per-booking lock guarantees and what it cannot:
     *
     *   GUARANTEED (local): SIRI issues at most one create request per
     *   booking at a time, and once a row is Created no further create
     *   is ever issued.
     *
     *   NOT GUARANTEED (remote): whether a create request that received
     *   no answer reached Zoom. That is exactly the ambiguous state, and
     *   it is resolved by evidence, never by assumption.
     *
     * Only `found` (one remote meeting carrying this booking's reference)
     * is evidence enough to act on automatically. `none` and
     * `inconclusive` keep remote_state_unknown, record what was seen, and
     * return a failed row; the automatic paths never re-run for a failed
     * row, and repeated explicit attempts repeat only the query.
     */
    private function reconcileAmbiguous(
        Booking $booking,
        BookingMeeting $existing,
        MeetingProviderInterface $provider,
        MeetingCreationContext $context,
        ?string $hostId,
    ): BookingMeeting {
        if (! $provider instanceof ReconcilesAmbiguousMeetings) {
            return $this->persistFailure(
                $booking,
                $provider->key(),
                'A previous attempt left the remote meeting state unknown and this provider cannot be queried; resolve it manually with meetings:resolve-ambiguous.',
                $this->withAmbiguityEvent($existing, [self::META_REMOTE_STATE_UNKNOWN => true], ['event' => 'reconciliation_unavailable']),
                $hostId,
            );
        }

        $reconciliation = $provider->findExistingMeeting($booking, $context);
        $queryEvent = [
            'event' => 'reconciliation',
            'status' => $reconciliation->status,
            'exhaustive' => $reconciliation->exhaustive,
            'candidate_ids' => $reconciliation->candidateIds,
        ];

        if ($reconciliation->isFound()) {
            $candidate = (string) $reconciliation->singleCandidate();

            // The reference in the agenda is unique to this booking, but a
            // local row may still already own that id (a race with an
            // admin adoption, a copied id). Never two bookings, one meeting.
            $this->assertNotOwnedByAnotherBooking($booking, $provider->key(), $candidate);

            $result = $provider->adoptExistingMeeting($booking, $candidate, $context);
            $meeting = $this->persistResult(
                $booking,
                $result,
                $hostId,
                $this->withAmbiguityEvent($existing, [], $queryEvent, ['event' => 'adopted', 'by' => 'reconciliation', 'provider_meeting_id' => $candidate]),
            );

            $this->audit->logSystem(
                'bookings',
                'meeting_adopted_after_ambiguity',
                sprintf('Booking %s: the remote meeting created by an earlier unanswered request was found and adopted.', $booking->reference),
                $booking,
                ['provider' => $provider->key(), 'provider_meeting_id' => $result->providerMeetingId],
            );

            return $meeting;
        }

        return $this->persistFailure(
            $booking,
            $provider->key(),
            sprintf('Remote meeting state still unknown after reconciliation: %s Resolve with meetings:resolve-ambiguous.', $reconciliation->reason),
            $this->withAmbiguityEvent($existing, [
                self::META_REMOTE_STATE_UNKNOWN => true,
                self::META_RECONCILIATION => [
                    'status' => $reconciliation->status,
                    'exhaustive' => $reconciliation->exhaustive,
                    'candidate_ids' => $reconciliation->candidateIds,
                    'checked_at' => now()->toIso8601String(),
                ],
            ], $queryEvent),
            $hostId,
        );
    }

    /**
     * Metadata for the next write, carrying the row's append-only
     * ambiguity history forward plus the new event(s), each stamped.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  ...$events
     * @return array<string, mixed>
     */
    private function withAmbiguityEvent(?BookingMeeting $existing, array $metadata, array ...$events): array
    {
        $history = $existing?->metadata[self::META_AMBIGUITY_HISTORY] ?? [];

        foreach ($events as $event) {
            $history[] = ['at' => now()->toIso8601String(), ...$event];
        }

        $metadata[self::META_AMBIGUITY_HISTORY] = array_values($history);

        return $metadata;
    }

    /**
     * The append-only ambiguity record on an existing row, to be merged
     * into whatever is written next so no later write can erase it.
     *
     * @return array<string, mixed>
     */
    private function carriedAmbiguityRecord(?BookingMeeting $existing): array
    {
        $metadata = $existing?->metadata ?? [];
        $carried = [];

        foreach ([self::META_AMBIGUITY_HISTORY, self::META_AMBIGUITY_RESOLUTION] as $key) {
            if (isset($metadata[$key])) {
                $carried[$key] = $metadata[$key];
            }
        }

        return $carried;
    }

    /** @throws BookingException when another local booking already owns this remote meeting */
    private function assertNotOwnedByAnotherBooking(Booking $booking, string $providerKey, string $providerMeetingId): void
    {
        $owner = BookingMeeting::query()
            ->where('provider', $providerKey)
            ->where('provider_meeting_id', $providerMeetingId)
            ->where('booking_id', '!=', $booking->id)
            ->first();

        if ($owner !== null) {
            throw new BookingException(sprintf(
                'Remote meeting %s already belongs to booking %s and cannot be adopted for %s.',
                $providerMeetingId,
                $owner->booking?->reference ?? $owner->booking_id,
                $booking->reference,
            ));
        }
    }

    // ── Explicit provider pin before any meeting exists ───────────────

    /**
     * The supported way to route ONE booking to a specific provider
     * without touching the global default: set its
     * meeting_provider_intent before its meeting is created, reserving
     * host capacity when the provider is capacity-governed. The meeting
     * itself is then created by the normal path — the BookingConfirmed
     * listener on confirmation, or the admin action — exactly as it
     * would be for a default-provider booking.
     *
     * Refused when a created meeting already exists (see
     * MeetingProviderSwitchNotSupportedException), when the provider is
     * not usable right now, or when Zoom capacity cannot be reserved.
     *
     * @throws BookingException
     */
    public function pinProvider(Booking $booking, string $providerKey, User $admin): Booking
    {
        Gate::forUser($admin)->authorize('manageMeeting', $booking);

        if ($booking->status->isTerminal()) {
            throw new BookingException(sprintf('Booking %s is %s; its meeting provider cannot be changed.', $booking->reference, $booking->status->label()));
        }

        $existing = $this->findForBooking($booking);

        if ($existing?->status === MeetingStatus::Created && $existing->provider !== $providerKey) {
            throw MeetingProviderSwitchNotSupportedException::between($booking->reference, $existing->provider, $providerKey);
        }

        // Fails clearly when the provider is disabled or misconfigured.
        $provider = $this->providers->resolve($providerKey);

        return $this->bookings->withMeetingCreationLock($booking->id, function () use ($booking, $provider, $admin): Booking {
            return DB::transaction(function () use ($booking, $provider, $admin): Booking {
                $previous = $booking->meeting_provider_intent;

                // Same lock order as acceptance: host rows first, then the
                // booking row, then the reservation.
                $this->hostCapacity->lockPoolFor($provider->key());

                /** @var Booking $locked */
                $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
                $locked->forceFill(['meeting_provider_intent' => $provider->key()])->save();

                if ($this->hostCapacity->appliesTo($provider->key())) {
                    // Throws MeetingHostCapacityException (no room, or the
                    // reservation switch is off) and rolls the pin back.
                    $this->hostCapacity->ensureReserved($locked);
                } elseif ($previous !== null && $this->hostCapacity->appliesTo($previous)) {
                    // Moving a not-yet-created booking OFF Zoom frees its host.
                    $this->hostCapacity->release($locked, MeetingHostCapacityService::RELEASE_CANCELLED);
                }

                $this->audit->logSystem(
                    'bookings',
                    'meeting_provider_pinned',
                    sprintf('Booking %s pinned to meeting provider %s before meeting creation.', $locked->reference, $provider->key()),
                    $locked,
                    ['provider' => $provider->key(), 'previous_intent' => $previous, 'admin_id' => $admin->id],
                );

                return $locked;
            });
        });
    }

    // ── Explicit resolution of an ambiguous create ────────────────────

    public function acknowledgeNoRemoteMeeting(Booking $booking, User $admin, string $reason): BookingMeeting
    {
        Gate::forUser($admin)->authorize('update', $booking);

        $reason = trim($reason);

        if ($reason === '') {
            throw new BookingException('A reason is required to declare that no remote meeting exists.');
        }

        return $this->bookings->withMeetingCreationLock($booking->id, function () use ($booking, $admin, $reason): BookingMeeting {
            $existing = $this->findForBooking($booking);

            if ($existing === null || ($existing->metadata[self::META_REMOTE_STATE_UNKNOWN] ?? false) !== true) {
                throw new BookingException(sprintf('Booking %s has no unresolved ambiguous meeting creation.', $booking->reference));
            }

            // Everything already recorded stays (the unanswered create, each
            // reconciliation and what it saw); the declaration is appended.
            $resolution = [
                'outcome' => 'no_remote_meeting',
                'by' => $admin->id,
                'reason' => Str::limit($reason, 500),
                'at' => now()->toIso8601String(),
            ];
            $metadata = $this->withAmbiguityEvent($existing, $existing->metadata ?? [], ['event' => 'resolved_no_remote_meeting', 'by' => $admin->id, 'reason' => $resolution['reason']]);
            $metadata[self::META_REMOTE_STATE_UNKNOWN] = false;
            $metadata[self::META_AMBIGUITY_RESOLUTION] = $resolution;

            $existing->forceFill(['metadata' => $metadata, 'updated_by' => $admin->id])->save();

            $this->audit->logSystem(
                'bookings',
                'meeting_ambiguity_acknowledged',
                sprintf('Booking %s: an administrator confirmed no remote meeting exists for the earlier unanswered create; a new create may proceed.', $booking->reference),
                $booking,
                ['provider' => $existing->provider, 'admin_id' => $admin->id, 'reason' => Str::limit($reason, 500)],
            );

            return $existing;
        });
    }

    public function adoptRemoteMeeting(Booking $booking, string $providerMeetingId, User $admin): BookingMeeting
    {
        Gate::forUser($admin)->authorize('update', $booking);

        $providerMeetingId = trim($providerMeetingId);

        if ($providerMeetingId === '') {
            throw new BookingException('A provider meeting id is required.');
        }

        return $this->bookings->withMeetingCreationLock($booking->id, function () use ($booking, $providerMeetingId, $admin): BookingMeeting {
            $existing = $this->findForBooking($booking);

            if ($existing === null || $existing->status === MeetingStatus::Created) {
                throw new BookingException(sprintf('Booking %s has no failed meeting to resolve.', $booking->reference));
            }

            $provider = $this->providers->resolve($existing->provider);

            if (! $provider instanceof ReconcilesAmbiguousMeetings) {
                throw new BookingException(sprintf('Provider "%s" cannot adopt an existing remote meeting.', $existing->provider));
            }

            $previousJoinUrl = $existing->join_url;

            $meeting = DB::transaction(function () use ($booking, $existing, $provider, $providerMeetingId, $admin): BookingMeeting {
                $context = new MeetingCreationContext(requestedBy: $admin->id);
                $hostId = null;
                $hostReference = null;

                if ($this->hostCapacity->appliesTo($provider->key())) {
                    $reservation = $this->hostCapacity->ensureReserved($booking, requiredHostId: $existing->platform_meeting_host_id);
                    $hostId = $reservation->platform_meeting_host_id;
                    $hostReference = $reservation->host->host_reference;
                    $context = new MeetingCreationContext(requestedBy: $admin->id, hostReference: $hostReference);
                }

                // An administrator's id is a claim, not proof. Before a
                // single byte is written locally or remotely the meeting is
                // READ and checked: it exists; it runs under the platform
                // host this booking is reserved on; no other local booking
                // owns it; its agenda does not name a different booking;
                // and its start time is not wildly elsewhere when the
                // agenda carries no reference at all.
                $evidence = $this->verifyAdoptable($booking, $provider, $providerMeetingId, $context, $hostReference);

                $result = $provider->adoptExistingMeeting($booking, $providerMeetingId, $context);
                $meeting = $this->persistResult(
                    $booking,
                    $result,
                    $hostId,
                    $this->withAmbiguityEvent($existing, [], ['event' => 'adopted', 'by' => $admin->id, 'provider_meeting_id' => $providerMeetingId, 'evidence' => $evidence]),
                );

                $this->audit->logSystem(
                    'bookings',
                    'meeting_adopted_by_admin',
                    sprintf('Booking %s: an administrator identified remote meeting %s as this booking\'s meeting; it was verified and adopted.', $booking->reference, $providerMeetingId),
                    $booking,
                    ['provider' => $provider->key(), 'provider_meeting_id' => $providerMeetingId, 'admin_id' => $admin->id, 'evidence' => $evidence],
                );

                return $meeting;
            });

            $this->dispatchTransitionEvents($booking, $meeting, $existing->status, $previousJoinUrl);

            if ($meeting->status === MeetingStatus::Created) {
                $this->registerRecordingIfEligible($booking, $meeting, $provider);
            }

            return $meeting;
        });
    }

    /**
     * The checks an operator-supplied meeting id must pass before
     * adoption. Returns the evidence recorded with the adoption.
     *
     * @return array<string, mixed>
     *
     * @throws BookingException when any check fails
     */
    private function verifyAdoptable(
        Booking $booking,
        ReconcilesAmbiguousMeetings $provider,
        string $providerMeetingId,
        MeetingCreationContext $context,
        ?string $hostReference,
    ): array {
        $identity = $provider->describeExistingMeeting($providerMeetingId, $context);

        if ($identity === null) {
            throw new BookingException(sprintf('Remote meeting %s does not exist at the provider.', $providerMeetingId));
        }

        $expectedHost = $hostReference ?? ($this->settings->zoom_host_user_id ?? $this->settings->zoom_host_email);

        if ($expectedHost === null || $expectedHost === '') {
            throw new BookingException('No platform host is configured to check the meeting against.');
        }

        if ($identity->hostId === null && $identity->hostEmail === null) {
            throw new BookingException(sprintf('Remote meeting %s reports no host; it cannot be verified as a platform meeting.', $providerMeetingId));
        }

        if (! $identity->isHostedBy($expectedHost)) {
            throw new BookingException(sprintf(
                'Remote meeting %s is not hosted by the platform host (%s); it belongs to another user or account.',
                $providerMeetingId,
                $expectedHost,
            ));
        }

        $this->assertNotOwnedByAnotherBooking($booking, $provider->key(), $providerMeetingId);

        $referenceInAgenda = $identity->bookingReferenceInAgenda();

        if ($referenceInAgenda !== null && $referenceInAgenda !== $booking->reference) {
            throw new BookingException(sprintf(
                'Remote meeting %s was created for booking %s, not %s.',
                $providerMeetingId,
                $referenceInAgenda,
                $booking->reference,
            ));
        }

        if ($referenceInAgenda === null && $identity->startsAt !== null) {
            $driftHours = abs($identity->startsAt->diffInHours($booking->starts_at->utc(), false));

            if ($driftHours > self::ADOPTION_TIME_TOLERANCE_HOURS) {
                throw new BookingException(sprintf(
                    'Remote meeting %s carries no booking reference and starts %s, more than %d hours from this lesson; it cannot be adopted.',
                    $providerMeetingId,
                    $identity->startsAt->toIso8601String(),
                    self::ADOPTION_TIME_TOLERANCE_HOURS,
                ));
            }
        }

        return [
            'host_matched' => $expectedHost,
            'agenda_reference' => $referenceInAgenda,
            'remote_starts_at' => $identity->startsAt?->toIso8601String(),
            'booking_starts_at' => $booking->starts_at->utc()->toIso8601String(),
        ];
    }

    /**
     * Registration only creates the Recording row (idempotent, cheap);
     * the actual capture is a SEPARATE queued job dispatched
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

        $existing = $this->findForBooking($booking);

        if ($existing?->status === MeetingStatus::Created && $existing->provider !== ManualMeetingProvider::KEY) {
            throw MeetingProviderSwitchNotSupportedException::between($booking->reference, $existing->provider, ManualMeetingProvider::KEY);
        }

        // Before the provider is even resolved: whether a replacement may
        // happen does not depend on the manual provider being enabled.
        if ($existing !== null && $existing->status !== MeetingStatus::Created) {
            $this->assertNoCaptureInFlight($booking, $existing);
        }

        try {
            $provider = $this->providers->resolve(ManualMeetingProvider::KEY);
        } catch (BookingException $e) {
            return $this->persistFailure($booking, ManualMeetingProvider::KEY, $e->getMessage());
        }

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

        // A package-funded booking is a prepaid paid lesson, so it
        // follows the PAID timing setting, not the demo one — the demo
        // arm stays pinned to NotRequired so a package lesson can never
        // inherit demo meeting behaviour. Anything that does not permit
        // delivery (pending, failed, refunded) still gets no meeting.
        return match (true) {
            $booking->payment_status === BookingPaymentStatus::NotRequired => $this->settings->create_after_demo_booking_confirmation,
            $booking->payment_status->permitsDelivery() => $this->settings->create_after_paid_booking_confirmation,
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

        $windowStartsAt = $this->windowStartsAt($meeting->starts_at ?? $booking->starts_at);
        $windowEndsAt = $this->windowEndsAt($meeting->ends_at ?? $booking->ends_at);

        if ($windowStartsAt === null || $windowEndsAt === null) {
            return MeetingJoinAvailability::NotReady;
        }

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
     * THE complete authoritative student meeting-URL disclosure
     * decision. A meeting URL is a sensitive
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
    public function joinWindowEndsAt(BookingMeeting $meeting): ?CarbonImmutable
    {
        return $this->windowEndsAt($meeting->ends_at ?? $meeting->booking?->ends_at);
    }

    public function joinWindowStartsAt(BookingMeeting $meeting): ?CarbonImmutable
    {
        return $this->windowStartsAt($meeting->starts_at ?? $meeting->booking?->starts_at);
    }

    /**
     * Shuts a finished lesson's meeting down at the provider.
     *
     * The window this closes at is the SAME one joinAvailabilityFor()
     * stops serving the link at (ends_at +
     * meeting_link_visible_after_minutes), so participants never see a
     * link to a meeting that has been closed, and a meeting is never
     * closed while SIRI is still offering it. Ordinary lessons therefore
     * end exactly where the platform already said they would.
     *
     * Deliberately narrow: it only ever ends what is running. It never
     * touches booking or lesson state, never cancels a meeting, and
     * never deletes anything — the row keeps its history and simply
     * records when it was closed.
     *
     * @throws BookingException when the provider fails (the sweep retries)
     */
    public function closeExpiredMeeting(BookingMeeting $meeting): bool
    {
        if (! $this->settings->meeting_auto_close_enabled) {
            return false;
        }

        if ($meeting->status !== MeetingStatus::Created || $this->closedAt($meeting) !== null) {
            return false;
        }

        $windowEndsAt = $this->joinWindowEndsAt($meeting);

        // gt(), not gte(): joinAvailabilityFor() still answers Available
        // AT the boundary instant, so closing must wait until strictly
        // past it. One instant of disagreement is one instant in which a
        // participant is handed a link to a meeting that has been shut.
        if ($windowEndsAt === null || ! now()->gt($windowEndsAt)) {
            return false;
        }

        try {
            $provider = $this->providers->resolve($meeting->provider);
        } catch (BookingException) {
            // The provider is disabled or misconfigured now. Nothing to
            // close through it, and this is not the place to raise that
            // alarm — meeting creation already does.
            return false;
        }

        if (! $provider instanceof EndsActiveMeetings) {
            return false;
        }

        if (! $provider->endActiveMeeting($meeting)) {
            // The provider had nothing it could act on for this meeting
            // (e.g. a Calendar-created Google conference). Recorded as
            // closed so the sweep stops revisiting it; link withholding
            // remains the control for those lessons.
            $this->markClosed($meeting, closedAtProvider: false);

            return false;
        }

        $this->markClosed($meeting, closedAtProvider: true);

        $booking = $meeting->booking;

        if ($booking !== null) {
            $this->audit->logSystem(
                'bookings',
                'meeting_auto_closed',
                sprintf('Meeting closed for booking %s after its join window ended.', $booking->reference),
                $booking,
                ['provider' => $meeting->provider],
            );
        }

        return true;
    }

    /** When this meeting was closed by the window sweep, if it has been. */
    private function closedAt(BookingMeeting $meeting): ?string
    {
        $closedAt = $meeting->metadata['closed_at'] ?? null;

        return is_string($closedAt) && $closedAt !== '' ? $closedAt : null;
    }

    /**
     * Metadata only — never the status column. `status` describes how
     * the meeting was CREATED (pending/created/failed/cancelled), and
     * overloading it with "finished" would change the meaning of every
     * existing read of it, including the join-window guard itself.
     */
    private function markClosed(BookingMeeting $meeting, bool $closedAtProvider): void
    {
        $meeting->forceFill([
            'metadata' => [
                ...($meeting->metadata ?? []),
                'closed_at' => now()->toIso8601String(),
                'closed_at_provider' => $closedAtProvider,
            ],
        ])->save();
    }

    private function windowStartsAt(?CarbonInterface $startsAt): ?CarbonImmutable
    {
        return $startsAt === null
            ? null
            : CarbonImmutable::parse($startsAt)->subMinutes(max(0, $this->settings->meeting_link_visible_before_minutes));
    }

    private function windowEndsAt(?CarbonInterface $endsAt): ?CarbonImmutable
    {
        return $endsAt === null
            ? null
            : CarbonImmutable::parse($endsAt)->addMinutes(max(0, $this->settings->meeting_link_visible_after_minutes));
    }

    public function joinLinkFor(Booking $booking): string
    {
        // A dedicated participant host (meet.sirieducation.com) when one is
        // configured; the same gateway, same checks, different address.
        // APP_URL is untouched, and the main-host link keeps working.
        // Only a validated bare HTTPS origin is ever used; an unusable
        // stored value falls back to the main host rather than to a
        // broken link.
        $origin = MeetingJoinHandoffService::normalizeOrigin($this->settings->participant_join_base_url);

        if ($origin !== null) {
            return $origin.'/join/'.$booking->getKey();
        }

        return route('dashboard.meetings.join', $booking);
    }

    public function participantJoinUrlFor(Booking $booking, User $viewer): ?string
    {
        // The meeting-host route has no account middleware in front of it
        // (a join grant is not a login), so account status is enforced
        // here for both roles — the same rule EnsureAccountIsActive applies.
        if (! $viewer->isActive()) {
            return null;
        }

        if ($viewer->id === $booking->student_id) {
            return $this->studentJoinUrlFor($booking, $viewer);
        }

        if ($viewer->id === $booking->instructor_id && $this->instructorMayJoin($viewer)) {
            return $this->joinUrlFor($booking, $this->settings->instructor_join_url_visible, $booking->lesson?->status);
        }

        return null;
    }

    public function participantJoinAvailabilityFor(Booking $booking, User $viewer): MeetingJoinAvailability
    {
        if (! $viewer->isActive()) {
            return MeetingJoinAvailability::Unavailable;
        }

        if ($viewer->id === $booking->student_id) {
            if (! $this->studentMayAct($viewer)) {
                return MeetingJoinAvailability::Unavailable;
            }

            return $this->joinAvailabilityFor($booking, $this->settings->student_join_url_visible);
        }

        if ($viewer->id === $booking->instructor_id && $this->instructorMayJoin($viewer)) {
            return $this->joinAvailabilityFor($booking, $this->settings->instructor_join_url_visible, $booking->lesson?->status);
        }

        return MeetingJoinAvailability::Unavailable;
    }

    /** Same gate the instructor workspace applies: an active account whose instructor status is publicly visible. */
    private function instructorMayJoin(User $instructor): bool
    {
        return $instructor->isActive()
            && in_array($instructor->profile?->instructor_status, InstructorStatus::publiclyVisible(), true);
    }

    public function studentJoinUrlFor(Booking $booking, ?User $viewer): ?string
    {
        if ($viewer === null || $booking->student_id !== $viewer->id || ! $this->studentMayAct($viewer)) {
            return null;
        }

        return $this->joinUrlFor($booking, $this->settings->student_join_url_visible);
    }

    public function studentJoinStatesFor(iterable $bookings, ?User $viewer): array
    {
        // The lifecycle guard is a fresh database read; for a schedule of
        // many lessons it runs once here, not once per row. Failing it
        // fails every row closed — the same plain "unavailable" a single
        // studentJoinUrlFor() call would surface, no state disclosed.
        $mayAct = $viewer !== null && $viewer->isActive() && $this->studentMayAct($viewer);

        $states = [];

        foreach ($bookings as $booking) {
            $states[$booking->getKey()] = $mayAct && $booking->student_id === $viewer->id
                ? $this->studentJoinStateOf($booking)
                : StudentJoinState::unavailable($booking->hasEnded());
        }

        return $states;
    }

    public function studentJoinStateFor(Booking $booking, ?User $viewer): StudentJoinState
    {
        return $this->studentJoinStatesFor([$booking], $viewer)[$booking->getKey()];
    }

    /**
     * The per-booking part of the student decision, once ownership and
     * lifecycle have passed: the ONE window calculation, the window edges
     * it was made from, and whether the answer can still change soon.
     */
    private function studentJoinStateOf(Booking $booking): StudentJoinState
    {
        $availability = $this->joinAvailabilityFor($booking, $this->settings->student_join_url_visible);
        $meeting = $booking->meeting;
        $opensAt = $meeting !== null ? $this->joinWindowStartsAt($meeting) : null;
        $closesAt = $meeting !== null ? $this->joinWindowEndsAt($meeting) : null;
        $available = $availability === MeetingJoinAvailability::Available;

        return new StudentJoinState(
            availability: $availability,
            joinUrl: $available ? $this->joinLinkFor($booking) : null,
            passcode: $available && filled($meeting?->password) ? (string) $meeting->password : null,
            opensAt: $opensAt,
            closesAt: $closesAt,
            // Re-render on a timer only while the answer can still change
            // on its own: from an hour before the window opens until it
            // has closed. Server-side enforcement is untouched.
            poll: $booking->status === BookingStatus::Confirmed
                && $closesAt !== null
                && now()->lt($closesAt->addMinute())
                && ($opensAt === null || now()->gt($opensAt->subHour())),
            ended: $booking->hasEnded(),
        );
    }

    /** The strict student lifecycle guard as a boolean: role + Active status, read fresh. */
    private function studentMayAct(User $student): bool
    {
        try {
            $this->studentLifecycle->assertEligibleForStudentAction($student);
        } catch (StudentActionNotAvailableException) {
            return false;
        }

        return true;
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

    /**
     * @param  array<string, mixed>  $extraMetadata  merged over the provider's result metadata —
     *                                               used to carry the ambiguity history across an adoption
     */
    private function persistResult(Booking $booking, MeetingCreationResult $result, ?string $hostId = null, array $extraMetadata = []): BookingMeeting
    {
        $failureReason = $result->status === MeetingStatus::Failed
            ? 'Meeting provider reported a conference creation failure.'
            : null;

        $meeting = $this->upsert($booking, [
            'provider' => $result->provider,
            ...($hostId !== null ? ['platform_meeting_host_id' => $hostId] : []),
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
            'metadata' => [...$result->metadata, ...$extraMetadata],
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

    /**
     * @param  array<string, mixed>|null  $metadata  null replaces nothing; an array REPLACES the row's metadata
     *                                               (so a definite failure after an ambiguous one clears the flag
     *                                               by passing [] and an ambiguous one sets it)
     */
    private function persistFailure(Booking $booking, string $providerKey, string $reason, ?array $metadata = null, ?string $hostId = null): BookingMeeting
    {
        $meeting = $this->upsert($booking, [
            'provider' => $providerKey,
            'status' => MeetingStatus::Failed,
            'failure_reason' => Str::limit($reason, 500),
            ...($metadata !== null ? ['metadata' => $metadata] : []),
            ...($hostId !== null ? ['platform_meeting_host_id' => $hostId] : []),
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
