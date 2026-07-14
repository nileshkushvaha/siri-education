<?php

declare(strict_types=1);

namespace App\Quality\Services;

use App\Models\InstructorQualityAlert;
use App\Models\InstructorRatingAggregate;
use App\Models\LessonReview;
use App\Models\ReviewReport;
use App\Quality\Contracts\AdminQualityDashboardRepositoryInterface;
use App\Quality\Contracts\AdminQualityDashboardServiceInterface;
use App\Quality\Contracts\QualitySignalRepositoryInterface;
use App\Quality\DTOs\AlertQueueFilters;
use App\Quality\DTOs\InstructorQualityAlertAdminData;
use App\Quality\DTOs\InstructorRatingHealthRow;
use App\Quality\DTOs\ModerationQueueFilters;
use App\Quality\DTOs\ModerationQueueRowData;
use App\Quality\DTOs\QualityDashboardSummaryData;
use App\Quality\DTOs\ReportQueueFilters;
use App\Quality\DTOs\ReportQueueRowData;
use App\Quality\DTOs\TrendDateRange;
use App\Quality\Enums\InstructorQualityAlertSeverity;
use App\Quality\Enums\InstructorQualityAlertStatus;
use App\Reviews\Enums\ReviewReportStatus;
use App\Reviews\Enums\StudentReviewStatus;
use App\Settings\ReviewSettings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class AdminQualityDashboardService implements AdminQualityDashboardServiceInterface
{
    private const int RECENT_TREND_SAMPLE = 5;

    public function __construct(
        private readonly AdminQualityDashboardRepositoryInterface $repository,
        private readonly QualitySignalRepositoryInterface $signals,
        private readonly ReviewSettings $settings,
    ) {}

    public function summary(?TrendDateRange $noShowCancellationPeriod = null): QualityDashboardSummaryData
    {
        $period = $noShowCancellationPeriod ?? TrendDateRange::lastDays(30);

        return new QualityDashboardSummaryData(
            submittedReviews: $this->repository->countReviewsByStatus(StudentReviewStatus::Submitted),
            flaggedReviews: $this->repository->countReviewsByStatus(StudentReviewStatus::Flagged),
            publishedReviews: $this->repository->countReviewsByStatus(StudentReviewStatus::Published),
            hiddenReviews: $this->repository->countReviewsByStatus(StudentReviewStatus::Hidden),
            rejectedReviews: $this->repository->countReviewsByStatus(StudentReviewStatus::Rejected),
            pendingReports: $this->repository->countReportsByStatus(ReviewReportStatus::Pending),
            reportsUnderReview: $this->repository->countReportsByStatus(ReviewReportStatus::UnderReview),
            openAlerts: $this->repository->countAlertsByStatus(InstructorQualityAlertStatus::Open),
            alertsUnderReview: $this->repository->countAlertsByStatus(InstructorQualityAlertStatus::UnderReview),
            highSeverityAlerts: $this->repository->countActiveAlertsBySeverity(InstructorQualityAlertSeverity::High),
            criticalSeverityAlerts: $this->repository->countActiveAlertsBySeverity(InstructorQualityAlertSeverity::Critical),
            instructorsWithPublishedRatings: $this->repository->instructorsWithPublishedRatingsCount(),
            platformEligiblePublishedReviewCount: $this->repository->platformEligiblePublishedReviewCount(),
            platformAverageRating: $this->repository->platformAverageRating(),
            instructorNoShowCount: $this->repository->instructorNoShowCount($period),
            instructorAttributedCancellationCount: $this->repository->instructorAttributedCancellationCount($period),
        );
    }

    public function moderationQueue(ModerationQueueFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        $identityMode = $this->settings->public_review_identity_mode;

        return $this->repository
            ->moderationQueue($filters, $perPage)
            ->through(fn (LessonReview $review): ModerationQueueRowData => ModerationQueueRowData::fromReview($review, $identityMode));
    }

    public function reportQueue(ReportQueueFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->repository
            ->reportQueue($filters, $perPage)
            ->through(fn (ReviewReport $report): ReportQueueRowData => ReportQueueRowData::fromReport($report));
    }

    public function alertQueue(AlertQueueFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->repository
            ->alertQueue($filters, $perPage)
            ->through(fn (InstructorQualityAlert $alert): InstructorQualityAlertAdminData => InstructorQualityAlertAdminData::fromAlert($alert));
    }

    public function lowRatedInstructors(): Collection
    {
        return $this->repository
            ->lowRatedInstructors($this->settings->quality_dashboard_low_rating_threshold, $this->settings->quality_dashboard_min_review_count)
            ->map(fn (InstructorRatingAggregate $aggregate): InstructorRatingHealthRow => $this->toHealthRow($aggregate));
    }

    public function highlyRatedInstructors(): Collection
    {
        return $this->repository
            ->highlyRatedInstructors($this->settings->quality_dashboard_high_rating_threshold, $this->settings->quality_dashboard_min_review_count)
            ->map(fn (InstructorRatingAggregate $aggregate): InstructorRatingHealthRow => $this->toHealthRow($aggregate));
    }

    public function trendSeries(TrendDateRange $range): array
    {
        return [
            'published_reviews' => $this->repository->publishedReviewsTrend($range),
            'low_rated_published_reviews' => $this->repository->lowRatedPublishedReviewsTrend($range, $this->settings->low_rating_threshold),
            'flagged_reviews' => $this->repository->flaggedReviewsTrend($range),
            'review_reports' => $this->repository->reviewReportsTrend($range),
            'quality_alerts' => $this->repository->qualityAlertsTrend($range),
            'instructor_no_shows' => $this->repository->instructorNoShowsTrend($range),
            'instructor_attributed_cancellations' => $this->repository->instructorAttributedCancellationsTrend($range),
        ];
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function toHealthRow(InstructorRatingAggregate $aggregate): InstructorRatingHealthRow
    {
        $period = TrendDateRange::lastDays(30);

        return new InstructorRatingHealthRow(
            instructorId: $aggregate->instructor_id,
            instructorName: $aggregate->instructor->name,
            averageRating: (float) $aggregate->overallAverage(),
            eligibleReviewCount: $aggregate->eligible_review_count,
            ratingDistribution: $aggregate->distribution(),
            recentRatingTrend: $this->recentRatingTrend($aggregate),
            openQualityAlertCount: InstructorQualityAlert::query()
                ->where('instructor_id', $aggregate->instructor_id)
                ->whereIn('status', [InstructorQualityAlertStatus::Open, InstructorQualityAlertStatus::UnderReview])
                ->count(),
            instructorNoShowCount: $this->signals->countInstructorNoShows($aggregate->instructor_id, $period->start),
            instructorAttributedCancellationCount: $this->signals->countInstructorAttributedCancellations($aggregate->instructor_id, $period->start),
        );
    }

    /**
     * The average of the most recent (up to 5) contributing reviews
     * minus the instructor's overall average — a lightweight,
     * defensibly-calculated trend signal, never a numeric quality
     * score. Returns null (never a fabricated 0) when there isn't
     * enough data to say anything meaningful.
     */
    private function recentRatingTrend(InstructorRatingAggregate $aggregate): ?float
    {
        if ($aggregate->eligible_review_count < 3) {
            return null;
        }

        $recent = $this->repository->recentContributionRatings($aggregate->instructor_id, self::RECENT_TREND_SAMPLE);

        if (count($recent) < 2) {
            return null;
        }

        $recentAverage = array_sum($recent) / count($recent);

        return round($recentAverage - $aggregate->overallAverage(), 2);
    }
}
