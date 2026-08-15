<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\RecurrenceData;
use App\Booking\DTOs\RecurringBookingResult;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\BookingStatus;
use App\Booking\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Authenticated-student flow on top of the core engine. Students pick
 * their teacher (new or previous); every booking still runs through
 * BookingServiceInterface — same rules, locking, events, notifications.
 */
interface StudentBookingServiceInterface
{
    /** @return Collection<int, User> teachers eligible for the subject + grade */
    public function availableTeachers(string $typeKey, string $subject, int $grade): Collection;

    /** @return Collection<int, User> teachers this student has booked before, most recent first */
    public function previousTeachers(User $student): Collection;

    /** @throws BookingException */
    public function book(StudentBookingData $data): Booking;

    /**
     * Books up to RecurrenceData::MAX_OCCURRENCES weekly repeats.
     * Conflicting occurrences are skipped and reported, not fatal.
     *
     * @throws BookingException when no occurrence could be booked
     */
    public function bookRecurring(StudentBookingData $data, RecurrenceData $recurrence): RecurringBookingResult;

    /** @return Collection<int, Booking> active, upcoming bookings — soonest first */
    public function upcomingClasses(User $student, ?int $limit = null): Collection;

    /** @return LengthAwarePaginator<int, Booking> full booking history, newest first */
    public function bookingHistory(User $student, int $perPage = 15, ?BookingStatus $status = null): LengthAwarePaginator;

    /** @return LengthAwarePaginator<int, Booking> bookings with a payment trail, newest first */
    public function paymentHistory(User $student, int $perPage = 15): LengthAwarePaginator;

    /** @return object{completed: int, no_show: int, cancelled: int, total: int} */
    public function attendanceStats(User $student): object;

    /** @return Collection<int, Booking> completed/no_show bookings, most recent first */
    public function attendanceHistory(User $student, int $limit = 50): Collection;

    /** @return object{completed_sessions: int, total_hours: float} */
    public function progressStats(User $student): object;

    /** @return Collection<int, object{subject: string, sessions: int}> subjects studied, most-booked first */
    public function subjectBreakdown(User $student): Collection;

    /** @return object{has_bookings: bool, has_completed_demo: bool} */
    public function bookingJourney(User $student): object;
}
