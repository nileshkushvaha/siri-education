<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Engagement;

/**
 * Instructor teaching-activity summary. Booking counts
 * reuse the Booking Operations report definitions (date basis
 * `bookings.created_at`); lesson-outcome counts reuse its
 * finalized-outcome definitions (`outcome_finalized_at`).
 *
 * Utilization decision (§6.5 — Outcome C): historical availability is
 * NOT reconstructable (`teacher_availability` is a current weekly
 * schedule updated in place, no versioning), so NO utilization rate is
 * reported. `bookedTeachingHours` (fully source-backed: Confirmed/
 * Completed booking durations whose scheduled start falls in the
 * period) and `publishedWeeklyAvailabilityHours` (an explicitly
 * CURRENT-STATE weekly figure) are separate metrics, never divided.
 *
 * `repeatPaidStudents` is a proxy, never labeled retention (§6.7):
 * distinct students holding ≥2 lifetime non-cancelled paid bookings
 * with the same instructor.
 */
final readonly class InstructorActivitySummaryData
{
    public function __construct(
        public int $demoBookings,
        public int $paidBookings,
        public int $completedLessons,
        public int $studentNoShows,
        public int $instructorNoShows,
        public int $technicalIssues,
        public int $cancelledBookings,
        public int $uniqueStudents,
        public int $uniquePaidStudents,
        public int $repeatPaidStudents,
        public float $bookedTeachingHours,
        public float $publishedWeeklyAvailabilityHours,
    ) {}
}
