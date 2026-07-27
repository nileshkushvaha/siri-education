<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Communication;

/**
 * Review submission rates, complementary to the existing
 * Reviews & Quality Dashboard (which remains the calculation owner for
 * moderation queues, alert queues and trends — never duplicated here).
 *
 * Submission-rate definition (SRS §4B gate): denominator = review
 * eligibility windows OPENED in the period that have CONCLUDED
 * (used, or expired by `expires_at` elapsing); revoked and
 * manual-review eligibilities are excluded from the denominator
 * (cancelled/indeterminate windows, mirroring the homework
 * cancelled-assignment rule). Numerator = used (`used_at` set). Null
 * (never 0%) at zero denominator. Demo/paid variants apply the same
 * definition per `lesson_type`.
 *
 * `platformAverageRating` and `publishedReviewCount` are read from
 * `instructor_rating_aggregates` with the identical formula owned by
 * AdminQualityDashboardRepository::platformAverageRating — hidden,
 * rejected and archived reviews never contribute because the aggregate
 * table only ever contains eligible published contributions
 * (maintained by InstructorRatingAggregateService).
 */
final readonly class ReviewQualityRatesData
{
    public function __construct(
        public ?float $submissionRate,
        public ?float $demoSubmissionRate,
        public ?float $paidSubmissionRate,
        public int $concludedWindowsInPeriod,
        public int $usedWindowsInPeriod,
        public int $revokedExcludedInPeriod,
        public int $manualReviewExcludedInPeriod,
        public ?float $platformAverageRating,
        public int $publishedEligibleReviewCount,
        public int $pendingReviewReports,
        public int $openQualityAlerts,
    ) {}
}
