<?php

declare(strict_types=1);

namespace App\Quality\DTOs;

/**
 * Platform-wide quality-assurance summary counts for the admin
 * dashboard's overview section. Every count is computed via database
 * aggregation against the same authoritative tables every other
 * Reviews/Quality-domain read path uses (`lesson_reviews`,
 * `review_reports`, `quality_alerts`, `instructor_rating_aggregates`)
 * — never a cached/derived business-intelligence store, and never
 * financial/payment/compensation data.
 */
final readonly class QualityDashboardSummaryData
{
    public function __construct(
        public int $submittedReviews,
        public int $flaggedReviews,
        public int $publishedReviews,
        public int $hiddenReviews,
        public int $rejectedReviews,
        public int $pendingReports,
        public int $reportsUnderReview,
        public int $openAlerts,
        public int $alertsUnderReview,
        public int $highSeverityAlerts,
        public int $criticalSeverityAlerts,
        public int $instructorsWithPublishedRatings,
        public int $platformEligiblePublishedReviewCount,
        public ?float $platformAverageRating,
        public int $instructorNoShowCount,
        public int $instructorAttributedCancellationCount,
    ) {}
}
