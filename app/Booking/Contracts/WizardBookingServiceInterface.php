<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\RecurrenceData;
use App\Booking\DTOs\RecurrencePatternData;
use App\Booking\DTOs\RecurringBookingResult;
use App\Booking\DTOs\SeriesSchedulePreviewData;
use App\Booking\DTOs\TimeSlotData;
use App\Booking\DTOs\WizardBookingData;
use App\Booking\Exceptions\BookingException;
use App\Curriculum\DTOs\AcademicContextData;
use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The authenticated-student booking-creation flow behind the `/book`
 * wizard (renamed from the pre-authenticated-only "guest booking"
 * concept; every caller is a logged-in, verified
 * student). Unlike the teacher-choice flow under `/dashboard/bookings`
 * (StudentBookingServiceInterface), the wizard never requires the
 * student to pick a teacher — availability is aggregated across
 * eligible teachers and the assignment engine chooses one on booking,
 * unless the student chose one (paid types offer the eligible
 * instructors, see instructorOptions()) or a specific instructor was
 * locked via a profile deep-link.
 */
interface WizardBookingServiceInterface
{
    /**
     * The instructors a student may choose for a paid lesson: the same
     * eligible set auto-assignment draws from (subject, level, status,
     * curriculum eligibility), grouped for continuity — the ones they
     * have booked before for this subject first (most recent first),
     * then bookable favourites, then the rest — as scalar cards, at
     * most twelve in total.
     *
     * @return array{previous: list<array<string, mixed>>, favourites: list<array<string, mixed>>, others: list<array<string, mixed>>}
     */
    public function instructorOptions(string $typeKey, string $subject, int $grade, ?AcademicContextData $academicContext, User $student): array;

    /**
     * @param  AcademicContextData|null  $academicContext  Phase 3 (§7/§10) — when supplied (country-aware Free
     *                                                     Demo only), narrows the candidate teacher SET itself to academically-eligible instructors
     *                                                     before availability is expanded, never a pick-then-reject-afterward pattern.
     * @return Collection<int, string> dates (Y-m-d, in $timezone) with at least one open slot
     */
    public function availableDates(
        string $typeKey,
        string $subject,
        int $grade,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $timezone = 'UTC',
        ?int $teacherId = null,
        ?AcademicContextData $academicContext = null,
    ): Collection;

    /** @return Collection<int, TimeSlotData> deduplicated across teachers, in $timezone */
    public function availableSlots(
        string $typeKey,
        string $subject,
        int $grade,
        CarbonImmutable $date,
        string $timezone = 'UTC',
        ?int $teacherId = null,
        ?AcademicContextData $academicContext = null,
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

    /**
     * Creates a repeating schedule from the student's full choice of
     * pattern — daily or weekly, multiple weekdays, every N weeks, and
     * an end on a date, after a number of classes, or not at all.
     *
     * There is no cap on the schedule's length. Classes are reserved
     * inside the confirmation horizon and the rest are generated as
     * their dates approach; $skippedDates are occurrences the student
     * removed while resolving conflicts in the Review step.
     *
     * @param  list<string>  $skippedDates  `Y-m-d` in the series' timezone
     * @param  array<string, string>  $timeOverrides  `Y-m-d => H:i:s`, for individual
     *                                                classes the student moved to another time on the same date
     *
     * @throws BookingException when the type does not allow recurrence,
     *                          when a wall clock in the schedule is impossible or doubled by
     *                          daylight saving, or when not one class could be reserved
     */
    public function bookSeries(WizardBookingData $data, RecurrencePatternData $pattern, array $skippedDates = [], array $timeOverrides = []): RecurringBookingResult;

    /**
     * The schedule a pattern would produce, with conflicts resolved
     * against real availability — what the Review step shows before
     * anything is reserved. Advisory: creation re-checks under the
     * instructor lock.
     *
     * @param  list<string>  $skippedDates
     * @param  array<string, string>  $timeOverrides
     */
    public function previewSeries(WizardBookingData $data, RecurrencePatternData $pattern, array $skippedDates = [], int $page = 1, array $timeOverrides = []): SeriesSchedulePreviewData;
}
