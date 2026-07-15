<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\RecurrenceData;
use App\Booking\DTOs\RecurringBookingResult;
use App\Booking\DTOs\TimeSlotData;
use App\Booking\DTOs\WizardBookingData;
use App\Booking\Exceptions\BookingException;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The authenticated-student booking-creation flow behind the `/book`
 * wizard (Phase 17U.3 — renamed from the pre-authenticated-only
 * "guest booking" concept; every caller is a logged-in, verified
 * student). Unlike the teacher-choice flow under `/dashboard/bookings`
 * (StudentBookingServiceInterface), the wizard never requires the
 * student to pick a teacher — availability is aggregated across
 * eligible teachers and the assignment engine chooses one on booking,
 * unless a specific instructor was locked via a profile deep-link.
 */
interface WizardBookingServiceInterface
{
    /** @return Collection<int, string> dates (Y-m-d, in $timezone) with at least one open slot */
    public function availableDates(
        string $typeKey,
        string $subject,
        int $grade,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $timezone = 'UTC',
        ?int $teacherId = null,
    ): Collection;

    /** @return Collection<int, TimeSlotData> deduplicated across teachers, in $timezone */
    public function availableSlots(
        string $typeKey,
        string $subject,
        int $grade,
        CarbonImmutable $date,
        string $timezone = 'UTC',
        ?int $teacherId = null,
    ): Collection;

    /** @throws BookingException */
    public function book(WizardBookingData $data): Booking;

    /**
     * Paid types only — the same instructor (locked, or auto-assigned
     * for the first occurrence) is used across the whole series.
     *
     * @throws BookingException when the type does not allow recurrence,
     *                          or when the first occurrence cannot be booked
     */
    public function bookRecurring(WizardBookingData $data, RecurrenceData $recurrence): RecurringBookingResult;
}
