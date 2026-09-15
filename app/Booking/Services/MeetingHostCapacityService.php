<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Enums\BookingLocationType;
use App\Booking\Enums\MeetingHostReservationStatus;
use App\Booking\Enums\MeetingStatus;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\MeetingHostCapacityException;
use App\Booking\Meetings\GoogleCalendarMeetProvider;
use App\Booking\Meetings\ZoomMeetingProvider;
use App\Booking\Repositories\MeetingHostReservationRepository;
use App\Models\Booking;
use App\Models\MeetingHostReservation;
use App\Models\PlatformMeetingHost;
use App\Services\AuditTrailService;
use App\Settings\MeetingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Capacity reservation for meeting providers whose host can only run a
 * bounded number of meetings at once — today one Zoom Pro user, one
 * meeting at a time. Instructor scheduling locks cannot express this:
 * two instructors with free calendars can still both be sold the same
 * hour on the one Zoom licence. This service is the single place that
 * question is answered.
 *
 * WHEN capacity is reserved: at the booking's ACCEPTANCE boundary —
 * inside BookingService::request()'s transaction, after the booking
 * row exists and before it commits. That covers free/demo, paid
 * (pending-payment hold), package-funded and recurring-occurrence
 * bookings alike, because every one of them is created there. A
 * pending-payment hold's reservation carries the hold's expiry for
 * visibility, but is RELEASED only when the hold is actually cancelled
 * (booking:release-expired → BookingService::cancel()), exactly as the
 * instructor's slot is. A payment that lands late but before release
 * therefore still finds its capacity, and confirmation only clears the
 * expiry. Nothing here charges anyone and nothing here switches
 * provider: an exhausted host throws and the whole booking rolls back.
 *
 * WHAT is reserved: the UTC interval the host must be free for —
 *
 *     [starts_at − visible_before − buffer,  ends_at + visible_after + buffer)
 *
 * reusing the join-window settings that already say when a participant
 * may join and when SIRI closes the meeting, plus an explicit
 * operational buffer. Half-open, so lessons that merely touch do not
 * collide. This prevents PLANNED overlap. It does not prove a remote
 * meeting has ended — a lesson that runs past its window still occupies
 * the licence at Zoom, and only the provider-side auto-end (a later
 * phase) can enforce that.
 *
 * LOCK ORDER (never vary it, or two paths can deadlock):
 *
 *   1. instructor advisory lock          BookingRepository::withInstructorLock()
 *   2. platform_meeting_hosts rows        lockPoolFor(): lockForUpdate(), ordered sort_order, id —
 *                                         taken FIRST inside the transaction, before any locking
 *                                         read or insert on bookings
 *   3. bookings                           the existing duplicate/availability re-reads and the insert
 *   4. meeting_host_reservations          locking overlap count, then insert/release
 *
 * Step 2 is what makes the decision atomic ACROSS instructors: whoever
 * holds the host rows is the only writer of reservations for that
 * provider until commit. It must come BEFORE step 3: the availability
 * re-read takes InnoDB gap locks on `bookings`, and a transaction that
 * held those gaps while waiting for the host rows would deadlock with
 * the host-holder trying to insert its booking into the same gap
 * (observed in MeetingHostCapacityConcurrencyTest before this order
 * was fixed). Taking the host rows first means a Zoom-bound booking
 * waits with NO gap locks held. Step 4 uses a locking read so it sees
 * the latest committed rows rather than the transaction's snapshot.
 * Every public mutation here asserts it is running inside a transaction.
 */
final class MeetingHostCapacityService
{
    public const string RELEASE_CANCELLED = 'cancelled';

    public const string RELEASE_HOLD_EXPIRED = 'hold_expired';

    public const string RELEASE_RESCHEDULED = 'rescheduled';

    public const string RELEASE_FINISHED = 'finished';

    public function __construct(
        private readonly MeetingHostReservationRepository $reservations,
        private readonly MeetingSettings $settings,
        private readonly AuditTrailService $audit,
        private readonly MeetingProviderResolver $providers,
    ) {}

    // ── Policy ────────────────────────────────────────────────────────

    /**
     * Is this provider capacity-governed? Zoom always is — the licence
     * limit exists whether or not SIRI is currently granting new
     * reservations. The switch below decides only whether NEW capacity
     * may be handed out; a governed provider never gets an unreserved
     * commitment either way.
     */
    public function appliesTo(?string $providerKey): bool
    {
        return $providerKey === ZoomMeetingProvider::KEY;
    }

    /**
     * May NEW reservations be granted? Off = the Zoom kill switch for
     * acceptance: Zoom-bound bookings are refused (never accepted
     * unreserved), existing reservations keep being honoured, released
     * and moved, and existing Zoom meetings are untouched.
     */
    public function reservationEnabled(): bool
    {
        return $this->settings->zoom_host_capacity_enabled;
    }

    /**
     * The provider a booking is being ACCEPTED for, or null when nothing
     * should be pinned. Recorded on bookings.meeting_provider_intent so a
     * later change of the global default cannot re-route an accepted
     * booking. Zoom is pinned whenever it is the default — with or
     * without the reservation switch — so that acceptance then runs
     * through reserve(), which refuses while the switch is off. Other
     * providers are pinned only while the feature is on (their
     * behaviour is otherwise unchanged from before it existed).
     */
    public function intendedProvider(BookingLocationType $locationType): ?string
    {
        if (! $this->settings->meetings_enabled || $locationType !== BookingLocationType::Online) {
            return null;
        }

        if ($this->settings->default_provider === ZoomMeetingProvider::KEY) {
            return ZoomMeetingProvider::KEY;
        }

        return $this->settings->zoom_host_capacity_enabled ? $this->settings->default_provider : null;
    }

    /**
     * The UTC interval a host is occupied for one lesson.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function occupiedInterval(CarbonImmutable $startsAt, CarbonImmutable $endsAt): array
    {
        $buffer = max(0, $this->settings->zoom_host_capacity_buffer_minutes);

        return [
            $startsAt->utc()->subMinutes(max(0, $this->settings->meeting_link_visible_before_minutes) + $buffer),
            $endsAt->utc()->addMinutes(max(0, $this->settings->meeting_link_visible_after_minutes) + $buffer),
        ];
    }

    // ── Mutations (all require an open transaction) ───────────────────

    /**
     * Step 2 of the lock order: take the provider's host rows now, at the
     * start of the caller's transaction, when this booking will need
     * capacity. No-op when the provider is not capacity-governed.
     * Re-locking the same rows later in the transaction (chooseHost) is
     * free — InnoDB already holds them for us.
     */
    public function lockPoolFor(?string $providerKey): void
    {
        if (! $this->appliesTo($providerKey)) {
            return;
        }

        $this->assertInTransaction();
        $this->reservations->lockActivePool(ZoomMeetingProvider::KEY);
    }

    /**
     * Reserve capacity for a booking that has none. $requiredHostId pins
     * the host (a meeting already exists there); $preferredHostId is
     * tried first but any host may serve.
     *
     * @throws MeetingHostCapacityException when no host has room
     */
    public function reserve(
        Booking $booking,
        ?CarbonImmutable $expiresAt = null,
        ?string $requiredHostId = null,
        ?string $preferredHostId = null,
        bool $allowWhileDisabled = false,
    ): MeetingHostReservation {
        $this->assertInTransaction();

        // Fail closed: while new reservations are switched off, a
        // Zoom-bound commitment is refused rather than accepted without
        // capacity. The one exception is the operator backfill, which
        // reserves for bookings that were accepted BEFORE the feature.
        if (! $allowWhileDisabled && ! $this->reservationEnabled()) {
            throw MeetingHostCapacityException::reservationDisabled();
        }

        $provider = ZoomMeetingProvider::KEY;
        [$from, $until] = $this->occupiedInterval($booking->starts_at, $booking->ends_at);

        $host = $this->chooseHost($provider, $from, $until, $booking->id, $requiredHostId, $preferredHostId);

        return $this->reservations->create([
            'platform_meeting_host_id' => $host->id,
            'booking_id' => $booking->id,
            'provider' => $provider,
            'lesson_starts_at' => $booking->starts_at->utc(),
            'lesson_ends_at' => $booking->ends_at->utc(),
            'occupies_from' => $from,
            'occupies_until' => $until,
            'status' => MeetingHostReservationStatus::Active,
            'expires_at' => $expiresAt?->utc(),
        ]);
    }

    /**
     * The provider that takes a Zoom-bound booking when no Zoom host has
     * room — MeetingSettings::zoom_capacity_fallback_provider, but only
     * while that provider can actually create meetings right now (enabled
     * and configured). Null means "refuse, as before". Evaluated on the
     * failure path only.
     */
    public function fallbackProvider(): ?string
    {
        $key = $this->settings->zoom_capacity_fallback_provider;

        if (blank($key) || $key === ZoomMeetingProvider::KEY) {
            return null;
        }

        try {
            $this->providers->resolve($key);
        } catch (BookingException) {
            return null;
        }

        return $key;
    }

    /**
     * reserve(), except that when no Zoom host has room and a fallback
     * provider is configured, the booking is pinned to that provider
     * instead of being refused. Returns the fallback key when that
     * happened, null when a Zoom reservation was taken.
     *
     * @throws MeetingHostCapacityException when refused and no fallback applies
     */
    public function reserveOrFallback(Booking $booking, ?CarbonImmutable $expiresAt = null): ?string
    {
        try {
            $this->reserve($booking, $expiresAt);

            return null;
        } catch (MeetingHostCapacityException $e) {
            if (! $this->fallBack($booking, $e, 'acceptance')) {
                throw $e;
            }

            return $booking->meeting_provider_intent;
        }
    }

    /**
     * Pins the booking to the fallback provider, when the failure allows
     * it (never for the operator kill switch, never when a Zoom meeting
     * already lives on a host) and one is configured. Audited as
     * meeting_host_capacity_fallback — the entry administrators are
     * notified from, because a Google Meet lesson only starts and
     * records once the platform Meet host has joined it.
     */
    public function fallBack(Booking $booking, MeetingHostCapacityException $failure, string $stage): bool
    {
        if (! $failure->mayFallBack) {
            return false;
        }

        $fallback = $this->fallbackProvider();

        if ($fallback === null) {
            return false;
        }

        $this->assertInTransaction();

        [$from, $until] = $this->occupiedInterval($booking->starts_at, $booking->ends_at);
        $label = $fallback === GoogleCalendarMeetProvider::KEY ? 'Google Meet' : $fallback;

        $booking->forceFill(['meeting_provider_intent' => $fallback])->save();

        $this->audit->logSystem(
            'bookings',
            'meeting_host_capacity_fallback',
            sprintf(
                'Zoom hosts were full for booking %s (%s to %s UTC); the lesson will run on %s instead. The platform Meet host must join this lesson for it to start and record.',
                $booking->reference,
                $from->format('Y-m-d H:i'),
                $until->format('Y-m-d H:i'),
                $label,
            ),
            $booking,
            [
                'from' => ZoomMeetingProvider::KEY,
                'to' => $fallback,
                'stage' => $stage,
                'lesson_starts_at' => $booking->starts_at->utc()->toIso8601String(),
                'lesson_ends_at' => $booking->ends_at->utc()->toIso8601String(),
                'occupies_from' => $from->toIso8601String(),
                'occupies_until' => $until->toIso8601String(),
                'reason' => $failure->detail(),
            ],
        );

        return true;
    }

    /**
     * The booking's active reservation, creating one if it has none —
     * the guard meeting creation runs before it talks to the provider.
     * A booking accepted before the feature was enabled (or before its
     * host was registered) is reserved here if capacity exists, and
     * refused clearly if not: a Zoom meeting is never created without a
     * reservation once the feature is on.
     *
     * @throws MeetingHostCapacityException
     */
    public function ensureReserved(Booking $booking, ?string $requiredHostId = null, bool $allowWhileDisabled = false): MeetingHostReservation
    {
        $this->assertInTransaction();

        $existing = $this->reservations->activeForBooking($booking->id, lock: true);

        if ($existing !== null) {
            if ($requiredHostId !== null && $existing->platform_meeting_host_id !== $requiredHostId) {
                throw new LogicException(sprintf(
                    'Booking %s is reserved on host %s but its meeting lives on host %s.',
                    $booking->reference,
                    $existing->platform_meeting_host_id,
                    $requiredHostId,
                ));
            }

            return $existing;
        }

        return $this->reserve($booking, $booking->reserved_until, requiredHostId: $requiredHostId, allowWhileDisabled: $allowWhileDisabled);
    }

    /**
     * Payment settled (or approval granted): the hold's expiry no longer
     * applies. Rechecks that the reservation is still there and, if a
     * booking somehow reached confirmation without one, tries to take
     * it now. That late attempt NEVER throws — the money has already
     * moved — it is recorded for an administrator instead, and meeting
     * creation will refuse a Zoom meeting for the booking until capacity
     * is found or the provider is changed deliberately.
     */
    public function confirmReservation(Booking $booking): ?MeetingHostReservation
    {
        if (! $this->appliesTo($booking->meeting_provider_intent)) {
            return null;
        }

        $this->assertInTransaction();

        $existing = $this->reservations->activeForBooking($booking->id, lock: true);

        if ($existing !== null) {
            if ($existing->expires_at !== null) {
                $existing->fill(['expires_at' => null])->save();
            }

            return $existing;
        }

        try {
            return $this->reserve($booking);
        } catch (MeetingHostCapacityException $e) {
            // Money has moved; if a fallback provider is configured the
            // lesson simply runs there, and meeting creation follows the
            // re-pinned intent.
            if ($this->fallBack($booking, $e, 'confirmation')) {
                return null;
            }

            Log::warning('Booking confirmed without Zoom host capacity', [
                'booking_id' => $booking->id,
                'reason' => $e->getMessage(),
            ]);

            $this->audit->logSystem(
                'bookings',
                'meeting_host_capacity_unreserved',
                sprintf('Booking %s was confirmed but no Zoom host capacity could be reserved: %s', $booking->reference, $e->getMessage()),
                $booking,
                ['provider' => ZoomMeetingProvider::KEY, 'reason' => $e->getMessage()],
            );

            return null;
        }
    }

    /**
     * Reschedule: take the replacement interval BEFORE letting go of the
     * original, all under the host lock inside the caller's transaction.
     * If the new interval cannot be reserved this throws and the
     * transaction rolls back with the original reservation untouched.
     *
     * Host stability: a booking whose Zoom meeting already exists is
     * pinned to that meeting's host (a Zoom meeting cannot change user
     * without being recreated); otherwise the current host is preferred
     * but any host with room will do.
     */
    public function move(Booking $booking, CarbonImmutable $newStartsAt, CarbonImmutable $newEndsAt): ?MeetingHostReservation
    {
        if (! $this->appliesTo($booking->meeting_provider_intent) && ! $this->hasZoomMeeting($booking)) {
            return null;
        }

        $this->assertInTransaction();

        $provider = ZoomMeetingProvider::KEY;
        $current = $this->reservations->activeForBooking($booking->id, lock: true);

        // A Zoom-bound booking with no reservation (accepted before the
        // feature) is a NEW claim; while the switch is off it is refused,
        // never moved to an unreserved hour. Run the backfill first.
        if ($current === null && ! $this->reservationEnabled()) {
            throw MeetingHostCapacityException::reservationDisabled();
        }
        $meetingHostId = $booking->meeting?->platform_meeting_host_id;
        $requiredHostId = $this->hasZoomMeeting($booking) ? $meetingHostId : null;

        [$from, $until] = $this->occupiedInterval($newStartsAt, $newEndsAt);

        // Acquire first: the overlap count ignores this booking's own
        // (still active) reservation, so the check is exactly "would
        // the new interval fit if the old one were gone".
        try {
            $host = $this->chooseHost(
                $provider,
                $from,
                $until,
                $booking->id,
                $requiredHostId,
                $current?->platform_meeting_host_id,
            );
        } catch (MeetingHostCapacityException $e) {
            // No Zoom meeting exists yet, so nothing binds this lesson to
            // Zoom: with a fallback configured it moves to that provider
            // and gives its Zoom slot back. A created Zoom meeting keeps
            // refusing — participants already hold its link.
            if ($requiredHostId === null && $this->fallBack($booking, $e, 'reschedule')) {
                if ($current !== null) {
                    $this->reservations->release($current, self::RELEASE_RESCHEDULED);
                }

                return null;
            }

            throw $e;
        }

        if ($current !== null) {
            $this->reservations->release($current, self::RELEASE_RESCHEDULED);
        }

        return $this->reservations->create([
            'platform_meeting_host_id' => $host->id,
            'booking_id' => $booking->id,
            'provider' => $provider,
            'lesson_starts_at' => $newStartsAt->utc(),
            'lesson_ends_at' => $newEndsAt->utc(),
            'occupies_from' => $from,
            'occupies_until' => $until,
            'status' => MeetingHostReservationStatus::Active,
            'expires_at' => $current?->expires_at,
        ]);
    }

    /** Release whatever the booking holds. Idempotent: a second call finds nothing active. */
    public function release(Booking $booking, string $reason): ?MeetingHostReservation
    {
        $this->assertInTransaction();

        $current = $this->reservations->activeForBooking($booking->id, lock: true);

        if ($current === null) {
            return null;
        }

        return $this->reservations->release($current, $reason);
    }

    // ── Internals ─────────────────────────────────────────────────────

    /**
     * Locks the provider's host pool (step 3 of the lock order), then
     * picks the first host whose locking overlap count is below its
     * capacity: the required host if given, else the preferred host,
     * else pool order.
     *
     * @throws MeetingHostCapacityException
     */
    private function chooseHost(
        string $provider,
        CarbonImmutable $from,
        CarbonImmutable $until,
        string $bookingId,
        ?string $requiredHostId,
        ?string $preferredHostId,
    ): PlatformMeetingHost {
        $pool = $this->reservations->lockActivePool($provider);

        if ($pool->isEmpty()) {
            throw MeetingHostCapacityException::noHostRegistered($provider);
        }

        if ($requiredHostId !== null) {
            $required = $pool->firstWhere('id', $requiredHostId);

            if ($required === null || ! $this->hasRoom($required, $from, $until, $bookingId)) {
                throw MeetingHostCapacityException::hostUnavailable($provider, $required?->host_reference ?? $requiredHostId);
            }

            return $required;
        }

        $ordered = $pool->sortBy(fn (PlatformMeetingHost $host): int => $host->id === $preferredHostId ? -1 : 0)->values();

        foreach ($ordered as $host) {
            if ($this->hasRoom($host, $from, $until, $bookingId)) {
                return $host;
            }
        }

        throw MeetingHostCapacityException::exhausted($provider, $from, $until);
    }

    private function hasRoom(PlatformMeetingHost $host, CarbonImmutable $from, CarbonImmutable $until, string $bookingId): bool
    {
        return $this->reservations->activeOverlapCount($host->id, $from, $until, ignoreBookingId: $bookingId) < max(1, $host->capacity);
    }

    private function hasZoomMeeting(Booking $booking): bool
    {
        $meeting = $booking->meeting;

        return $meeting !== null
            && $meeting->provider === ZoomMeetingProvider::KEY
            && $meeting->status === MeetingStatus::Created;
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Meeting host capacity changes must run inside the caller\'s database transaction.');
        }
    }
}
