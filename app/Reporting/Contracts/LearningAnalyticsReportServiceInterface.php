<?php

declare(strict_types=1);

namespace App\Reporting\Contracts;

use App\Models\User;
use App\Reporting\DTOs\Learning\HomeworkAnalyticsData;
use App\Reporting\DTOs\Learning\HomeworkReviewRow;
use App\Reporting\DTOs\Learning\LearningGoalAnalyticsData;
use App\Reporting\DTOs\Learning\LearningPlanAnalyticsData;
use App\Reporting\DTOs\Learning\LearningPlanReviewRow;
use App\Reporting\DTOs\Learning\LearningTrendsData;
use App\Reporting\DTOs\Learning\MilestoneReviewAnalyticsData;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read-only Learning Analytics (SRS Chapter 19 Learning
 * Dashboard, Learning Plan Report, Homework Report). Strictly
 * read-only: no method mutates a plan, goal, milestone, review or
 * homework record, dispatches an event or notification, or writes an
 * audit row. Every method independently requires
 * `ViewLearningReports`; student identity in table rows additionally
 * follows the full-identity permission (`ViewStudentReports`).
 * Curriculum progress, resource usage, review-time and consistency
 * scores are structurally absent — their provenance gates failed
 * (no curriculum domain, no resource access events, no grading
 * timestamp, no approved consistency definition).
 */
interface LearningAnalyticsReportServiceInterface
{
    /** @throws AuthorizationException */
    public function planSummary(User $user, ReportingPeriod $period, ReportFilters $filters): LearningPlanAnalyticsData;

    /** @throws AuthorizationException */
    public function goalSummary(User $user, ReportingPeriod $period, ReportFilters $filters): LearningGoalAnalyticsData;

    /** @throws AuthorizationException */
    public function homeworkSummary(User $user, ReportingPeriod $period, ReportFilters $filters): HomeworkAnalyticsData;

    /** @throws AuthorizationException */
    public function milestoneReviewSummary(User $user, ReportingPeriod $period, ReportFilters $filters): MilestoneReviewAnalyticsData;

    /** @throws AuthorizationException */
    public function trends(User $user, ReportingPeriod $period, ReportFilters $filters): LearningTrendsData;

    /**
     * Paginated Learning Plan review table (masked identity, no
     * private notes, attention flags source-backed).
     *
     * @return LengthAwarePaginator<int, LearningPlanReviewRow>
     *
     * @throws AuthorizationException
     */
    public function planReviewTable(User $user, ReportingPeriod $period, ReportFilters $filters, int $perPage = 25): LengthAwarePaginator;

    /**
     * Paginated homework attention table (currently overdue + awaiting
     * grading; no submission content, feedback or grade).
     *
     * @return LengthAwarePaginator<int, HomeworkReviewRow>
     *
     * @throws AuthorizationException
     */
    public function homeworkAttentionTable(User $user, ReportingPeriod $period, ReportFilters $filters, int $perPage = 25): LengthAwarePaginator;

    public function freshness(ReportingPeriod $period): OperationsReportFreshnessData;

    public function canView(User $user): bool;
}
