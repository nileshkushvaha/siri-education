<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Engagement;

/**
 * Phase 18D — quality summary, gated by `ViewReviewQualityReports` on
 * top of instructor-report access. Rating figures reuse the Phase 17K
 * aggregate (published public reviews only — hidden/rejected/archived
 * structurally excluded by the aggregate itself). Alert counts read
 * `quality_alerts` active statuses (Open/UnderReview) only. Never a
 * combined quality score, never reporter identity, never alert notes.
 */
final readonly class InstructorQualitySummaryData
{
    public function __construct(
        public ?float $platformAverageRating,
        public int $instructorsWithPublishedRatings,
        public int $activeQualityAlerts,
    ) {}
}
