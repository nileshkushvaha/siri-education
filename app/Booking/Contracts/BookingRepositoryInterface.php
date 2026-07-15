<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\CreateBookingData;
use App\Booking\Enums\BookingActivityAction;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\MeetingStatus;
use App\Booking\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

    /**
     * Persist a status change plus any extra attributes
     * (e.g. cancelled_by, cancellation_reason).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transitionStatus(Booking $booking, BookingStatus $status, array $attributes = []): Booking;

    public function reschedule(Booking $booking, CarbonImmutable $startsAt, CarbonImmutable $endsAt): Booking;

    /**
     * Overlap check against active bookings for the instructor.
     * $bufferMinutes pads the checked range on both sides. Every slot
     * is exclusive — any overlap blocks.
     */
    public function hasOverlap(
        int $instructorId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?string $ignoreBookingId = null,
        int $bufferMinutes = 0,
    ): bool;

    /** Active bookings for the instructor on the given day (UTC). */
    public function activeCountForDay(int $instructorId, CarbonImmutable $day, ?string $ignoreBookingId = null): int;

    /** Upcoming active bookings for an instructor — the workload measure. */
    public function activeUpcomingCountForInstructor(int $instructorId): int;

    /** Same student + instructor + type + start already actively booked. */
    public function duplicateExists(CreateBookingData $data): bool;

    /** @return Collection<int, Booking> active bookings intersecting [$from, $to) */
    public function activeBetween(int $instructorId, CarbonImmutable $from, CarbonImmutable $to): Collection;

    /** @return Collection<int, Booking> */
    public function upcomingForUser(int $userId): Collection;

    /** @return LengthAwarePaginator<int, Booking> full history (any status), newest first */
    public function paginatedForUser(int $userId, int $perPage = 15, ?BookingStatus $status = null): LengthAwarePaginator;

    /** @return LengthAwarePaginator<int, Booking> bookings with a payment trail (paid types), newest first */
    public function paginatedPaymentsForUser(int $userId, int $perPage = 15): LengthAwarePaginator;

    /** @return object{completed: int, no_show: int, cancelled: int, total: int} attendance counts for a student */
    public function attendanceStatsForUser(int $userId): object;

    /** @return Collection<int, Booking> completed/no_show bookings for a student, most recent first */
    public function attendanceHistoryForUser(int $userId, int $limit = 50): Collection;

    /** @return object{completed_sessions: int, total_hours: float} lifetime session stats for a student */
    public function progressStatsForUser(int $userId): object;

    /** @return Collection<int, object{subject: string, sessions: int}> subjects studied, most-booked first */
    public function subjectBreakdownForUser(int $userId): Collection;

    /** @return Collection<int, User> instructors the student has booked (non-cancelled), most recent first */
    public function previousInstructorsForStudent(int $studentId): Collection;

    public function updatePaymentStatus(Booking $booking, BookingPaymentStatus $status, ?string $reference = null): Booking;

    public function findByPaymentReference(string $reference): ?Booking;

    /** Release the payment hold once payment settles. */
    public function clearReservation(Booking $booking): Booking;

    /**
     * Persist a meeting status change plus any extra meeting attributes
     * (provider, ref, url, host url, password, metadata).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateMeeting(Booking $booking, MeetingStatus $status, array $attributes = []): Booking;

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
     * Serialize booking mutations per instructor — the race-condition guard.
     * Run duplicate/overlap re-checks and the insert inside the callback.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws BookingException when the lock cannot be acquired
     */
    public function withInstructorLock(int $instructorId, Closure $callback): mixed;
}
