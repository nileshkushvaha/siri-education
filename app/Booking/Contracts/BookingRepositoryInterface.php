<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\CreateBookingData;
use App\Booking\Enums\BookingActivityAction;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;

/**
 * All booking persistence goes through this contract.
 * Services never write raw Eloquent queries (docs/decisions.md).
 */
interface BookingRepositoryInterface
{
    /** @param array<string, mixed> $attributes service-computed extras (booking_type_id, payment snapshot, …) */
    public function create(CreateBookingData $data, BookingStatus $status, array $attributes = []): Booking;

    public function find(string $id): ?Booking;

    public function findOrFail(string $id): Booking;

    public function findByReference(string $reference): ?Booking;

    /** Spam guard for unauthenticated bookings. */
    public function activeUpcomingCountForGuestEmail(string $email): int;

    /**
     * Persist a status change plus any extra attributes
     * (e.g. cancelled_by, cancellation_reason).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transitionStatus(Booking $booking, BookingStatus $status, array $attributes = []): Booking;

    public function reschedule(Booking $booking, CarbonImmutable $startsAt, CarbonImmutable $endsAt): Booking;

    /**
     * Overlap check against active bookings for the host. When
     * $sharedSlotTypeKey is given, bookings of that type occupying the
     * exact same slot are ignored (group types share a slot).
     * $bufferMinutes pads the checked range on both sides (shared-slot
     * equality still compares the unpadded slot).
     */
    public function hasOverlap(
        int $hostId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?string $ignoreBookingId = null,
        ?string $sharedSlotTypeKey = null,
        int $bufferMinutes = 0,
    ): bool;

    /** Active bookings for the host on the given day (UTC). */
    public function activeCountForDay(int $hostId, CarbonImmutable $day, ?string $ignoreBookingId = null): int;

    /** Upcoming active bookings for a host — the workload measure. */
    public function activeUpcomingCountForHost(int $hostId): int;

    /** Same attendee + host + type + start already actively booked. */
    public function duplicateExists(CreateBookingData $data): bool;

    /** Attendees already booked into an exact slot — used for capacity caps. */
    public function attendeeCountForSlot(int $hostId, string $typeKey, CarbonImmutable $startsAt): int;

    /** @return Collection<int, Booking> active bookings intersecting [$from, $to) */
    public function activeBetween(int $hostId, CarbonImmutable $from, CarbonImmutable $to): Collection;

    /** @return Collection<int, Booking> */
    public function upcomingForUser(int $userId): Collection;

    /** @return Collection<int, User> hosts the attendee has booked (non-cancelled), most recent first */
    public function previousHostsForAttendee(int $attendeeId): Collection;

    public function updatePaymentStatus(Booking $booking, BookingPaymentStatus $status, ?string $reference = null): Booking;

    public function findByPaymentReference(string $reference): ?Booking;

    /** Release the payment hold once payment settles. */
    public function clearReservation(Booking $booking): Booking;

    /** @return Collection<int, Booking> pending bookings whose payment hold has lapsed */
    public function expiredReservations(): Collection;

    /** Append an entry to the booking's domain timeline (booking_activities). */
    public function logActivity(
        Booking $booking,
        BookingActivityAction $action,
        BookingActor $actorType,
        ?int $actorId = null,
        ?BookingStatus $from = null,
        ?BookingStatus $to = null,
        array $meta = [],
    ): void;

    /**
     * Serialize booking mutations per host — the race-condition guard.
     * Run duplicate/overlap/capacity re-checks and the insert inside
     * the callback.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws BookingException when the lock cannot be acquired
     */
    public function withHostLock(int $hostId, Closure $callback): mixed;
}
