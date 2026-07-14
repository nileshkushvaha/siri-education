<?php

declare(strict_types=1);

namespace App\Quality\Contracts;

use App\Quality\DTOs\AlertQueueFilters;
use App\Quality\DTOs\InstructorQualityAlertAdminData;
use App\Quality\DTOs\InstructorRatingHealthRow;
use App\Quality\DTOs\ModerationQueueFilters;
use App\Quality\DTOs\ModerationQueueRowData;
use App\Quality\DTOs\QualityDashboardSummaryData;
use App\Quality\DTOs\ReportQueueFilters;
use App\Quality\DTOs\ReportQueueRowData;
use App\Quality\DTOs\TrendDateRange;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The single read boundary for the admin Reviews & Quality dashboard.
 * Aggregates existing authoritative records (`lesson_reviews`,
 * `review_reports`, `quality_alerts`, `instructor_rating_aggregates`)
 * without ever changing them — every method here is read-only.
 */
interface AdminQualityDashboardServiceInterface
{
    public function summary(?TrendDateRange $noShowCancellationPeriod = null): QualityDashboardSummaryData;

    /** @return LengthAwarePaginator<int, ModerationQueueRowData> */
    public function moderationQueue(ModerationQueueFilters $filters, int $perPage = 25): LengthAwarePaginator;

    /** @return LengthAwarePaginator<int, ReportQueueRowData> */
    public function reportQueue(ReportQueueFilters $filters, int $perPage = 25): LengthAwarePaginator;

    /** @return LengthAwarePaginator<int, InstructorQualityAlertAdminData> */
    public function alertQueue(AlertQueueFilters $filters, int $perPage = 25): LengthAwarePaginator;

    /** @return Collection<int, InstructorRatingHealthRow> */
    public function lowRatedInstructors(): Collection;

    /** @return Collection<int, InstructorRatingHealthRow> */
    public function highlyRatedInstructors(): Collection;

    /** @return array<string, array<string, int>> keyed by series name, each an ISO-date => count series */
    public function trendSeries(TrendDateRange $range): array;
}
