<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Engagement;

/**
 * One row of the instructor performance table. Rating figures come
 * from the instructor rating aggregate's own accessors (never recomputed).
 * `activeQualityAlerts` is null — not zero — when the viewer lacks
 * `ViewReviewQualityReports`; the view renders nothing for null. No
 * earnings, compensation, settlement, withdrawal, KYC, or private
 * contact field exists on this DTO at all.
 */
final readonly class InstructorPerformanceRow
{
    public function __construct(
        public int $instructorId,
        public string $instructorLabel,
        public string $statusLabel,
        public ?string $countryLabel,
        public int $demoBookings,
        public int $paidBookings,
        public int $completedLessons,
        public int $uniqueStudents,
        public int $instructorNoShows,
        public float $bookedHours,
        public ?float $averageRating,
        public int $reviewCount,
        public ?int $activeQualityAlerts,
        public ?string $drillDownUrl,
    ) {}
}
