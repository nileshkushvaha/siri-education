<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\RecurrenceData;
use App\Booking\DTOs\RecurringBookingResult;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\User;
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
}
