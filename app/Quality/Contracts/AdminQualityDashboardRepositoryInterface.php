<?php

declare(strict_types=1);

namespace App\Quality\Contracts;

use App\Models\InstructorRatingAggregate;
use App\Quality\DTOs\AlertQueueFilters;
use App\Quality\DTOs\ModerationQueueFilters;
use App\Quality\DTOs\ReportQueueFilters;
use App\Quality\DTOs\TrendDateRange;
use App\Quality\Enums\InstructorQualityAlertSeverity;
use App\Quality\Enums\InstructorQualityAlertStatus;
use App\Reviews\Enums\ReviewReportStatus;
use App\Reviews\Enums\StudentReviewStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Every method here is read-only database aggregation against
 * existing authoritative tables — no method in this repository (or
 * anything built on top of it) ever writes to `lesson_reviews`,
 * `review_reports`, `quality_alerts`, or `instructor_rating_aggregates`.
 */
interface AdminQualityDashboardRepositoryInterface
{
    public function countReviewsByStatus(StudentReviewStatus $status): int;

    public function countReportsByStatus(ReviewReportStatus $status): int;

    public function countAlertsByStatus(InstructorQualityAlertStatus $status): int;

    /** Counts only among currently-active (Open/UnderReview) alerts — a workload metric, not a lifetime total. */
    public function countActiveAlertsBySeverity(InstructorQualityAlertSeverity $severity): int;

    public function instructorsWithPublishedRatingsCount(): int;

    public function platformEligiblePublishedReviewCount(): int;

    public function platformAverageRating(): ?float;

    public function instructorNoShowCount(TrendDateRange $range): int;

    public function instructorAttributedCancellationCount(TrendDateRange $range): int;

    public function moderationQueue(ModerationQueueFilters $filters, int $perPage): LengthAwarePaginator;

    public function reportQueue(ReportQueueFilters $filters, int $perPage): LengthAwarePaginator;

    public function alertQueue(AlertQueueFilters $filters, int $perPage): LengthAwarePaginator;

    /** @return Collection<int, InstructorRatingAggregate> */
    public function lowRatedInstructors(float $threshold, int $minReviewCount, int $limit = 25): Collection;

    /** @return Collection<int, InstructorRatingAggregate> */
    public function highlyRatedInstructors(float $threshold, int $minReviewCount, int $limit = 25): Collection;

    /** @return array<string, int> ISO date (Y-m-d) => count, every day in range present even when zero */
    public function publishedReviewsTrend(TrendDateRange $range): array;

    /** @return array<string, int> */
    public function lowRatedPublishedReviewsTrend(TrendDateRange $range, int $threshold): array;

    /** @return array<string, int> */
    public function flaggedReviewsTrend(TrendDateRange $range): array;

    /** @return array<string, int> */
    public function reviewReportsTrend(TrendDateRange $range): array;

    /** @return array<string, int> */
    public function qualityAlertsTrend(TrendDateRange $range): array;

    /** @return array<string, int> */
    public function instructorNoShowsTrend(TrendDateRange $range): array;

    /** @return array<string, int> */
    public function instructorAttributedCancellationsTrend(TrendDateRange $range): array;

    /** Most recent $limit contributing overall ratings for this instructor, newest first — from the contribution ledger, the same authoritative source the aggregate itself is built from. @return list<int> */
    public function recentContributionRatings(int $instructorId, int $limit): array;
}
