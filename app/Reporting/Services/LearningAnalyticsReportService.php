<?php

declare(strict_types=1);

namespace App\Reporting\Services;

use App\Filament\Resources\StudentLearningPlans\StudentLearningPlanResource;
use App\Models\StudentLearningPlan;
use App\Models\User;
use App\Reporting\Contracts\LearningAnalyticsReportServiceInterface;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\DTOs\Learning\HomeworkAnalyticsData;
use App\Reporting\DTOs\Learning\HomeworkReviewRow;
use App\Reporting\DTOs\Learning\LearningGoalAnalyticsData;
use App\Reporting\DTOs\Learning\LearningPlanAnalyticsData;
use App\Reporting\DTOs\Learning\LearningPlanReviewRow;
use App\Reporting\DTOs\Learning\LearningTrendsData;
use App\Reporting\DTOs\Learning\MilestoneReviewAnalyticsData;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use App\Reporting\Enums\ReportDataFreshness;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Repositories\HomeworkAnalyticsRepository;
use App\Reporting\Repositories\LearningPlanAnalyticsRepository;
use App\Reporting\ValueObjects\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/** See the interface for the contract. */
final class LearningAnalyticsReportService implements LearningAnalyticsReportServiceInterface
{
    private const string REPORT_KEY = 'learning_progress';

    public function __construct(
        private readonly LearningPlanAnalyticsRepository $plans,
        private readonly HomeworkAnalyticsRepository $homework,
        private readonly ReportAccessContextInterface $access,
        private readonly ReportRegistryInterface $registry,
    ) {}

    public function planSummary(User $user, ReportingPeriod $period, ReportFilters $filters): LearningPlanAnalyticsData
    {
        $this->authorize($user);

        return $this->plans->planSummary($period, $this->restrict($filters));
    }

    public function goalSummary(User $user, ReportingPeriod $period, ReportFilters $filters): LearningGoalAnalyticsData
    {
        $this->authorize($user);

        return $this->plans->goalSummary($period, $this->restrict($filters));
    }

    public function homeworkSummary(User $user, ReportingPeriod $period, ReportFilters $filters): HomeworkAnalyticsData
    {
        $this->authorize($user);

        return $this->homework->summary($period, $this->restrict($filters));
    }

    public function milestoneReviewSummary(User $user, ReportingPeriod $period, ReportFilters $filters): MilestoneReviewAnalyticsData
    {
        $this->authorize($user);

        return $this->plans->milestoneReviewSummary($period, $this->restrict($filters));
    }

    public function trends(User $user, ReportingPeriod $period, ReportFilters $filters): LearningTrendsData
    {
        $this->authorize($user);

        $filters = $this->restrict($filters);

        return new LearningTrendsData(
            plansCreated: $this->plans->planTrend('created_at', $period, $filters),
            plansActivated: $this->plans->planTrend('started_at', $period, $filters),
            plansCompleted: $this->plans->planTrend('completed_at', $period, $filters),
            homeworkAssigned: $this->homework->trend('created_at', $period, $filters),
            homeworkSubmitted: $this->homework->trend('submitted_at', $period, $filters),
            milestonesAchieved: $this->plans->milestoneAchievedTrend($period, $filters),
            reviewsCompleted: $this->plans->reviewCompletedTrend($period, $filters),
        );
    }

    public function planReviewTable(User $user, ReportingPeriod $period, ReportFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        $this->authorize($user);

        $canViewFullIdentity = $this->access->canViewFullStudentIdentity($user);
        $canDrillDown = Gate::forUser($user)->allows('viewAny', StudentLearningPlan::class);
        $page = Paginator::resolveCurrentPage('planPage');

        $result = $this->plans->planReviewRows($this->restrict($filters), $page, $perPage);

        $rows = $result->rows->map(fn ($row) => new LearningPlanReviewRow(
            planId: (int) $row->id,
            studentLabel: $this->personLabel((string) $row->student_first_name, (string) $row->student_last_name, $canViewFullIdentity),
            instructorLabel: $row->instructor_first_name !== null
                ? trim($row->instructor_first_name.' '.($row->instructor_last_name ?? ''))
                : null,
            subjectLabel: $row->subject_name !== null ? (string) $row->subject_name : null,
            statusLabel: ucwords(str_replace('_', ' ', (string) $row->status)),
            startedAtUtc: $row->started_at !== null ? CarbonImmutable::parse((string) $row->started_at, 'UTC') : null,
            targetDate: $row->target_completion_date !== null ? (string) $row->target_completion_date : null,
            progressPercent: (int) $row->progress_percent,
            milestonesAchieved: (int) $row->milestones_achieved,
            milestonesTotal: (int) $row->milestones_total,
            lastReviewAtUtc: $row->last_review_at !== null ? CarbonImmutable::parse((string) $row->last_review_at, 'UTC') : null,
            reviewDue: (string) $row->status === 'review_due',
            targetDatePassed: (string) $row->status === 'active'
                && $row->target_completion_date !== null
                && CarbonImmutable::parse((string) $row->target_completion_date)->isPast(),
            missingInstructor: (string) $row->status === 'active' && $row->primary_instructor_user_id === null,
            drillDownUrl: $canDrillDown
                ? StudentLearningPlanResource::getUrl('edit', ['record' => (int) $row->id])
                : null,
        ));

        return new Paginator($rows, $result->total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'planPage',
        ]);
    }

    public function homeworkAttentionTable(User $user, ReportingPeriod $period, ReportFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        $this->authorize($user);

        $canViewFullIdentity = $this->access->canViewFullStudentIdentity($user);
        $page = Paginator::resolveCurrentPage('homeworkPage');

        $result = $this->homework->attentionRows($this->restrict($filters), $page, $perPage);

        $rows = $result->rows->map(fn ($row) => new HomeworkReviewRow(
            homeworkId: (string) $row->id,
            studentLabel: $this->personLabel((string) $row->student_first_name, (string) $row->student_last_name, $canViewFullIdentity),
            teacherLabel: trim($row->teacher_first_name.' '.($row->teacher_last_name ?? '')),
            subjectText: (string) $row->subject,
            statusLabel: ucfirst((string) $row->status),
            assignedAtUtc: CarbonImmutable::parse((string) $row->created_at, 'UTC'),
            dueAtUtc: CarbonImmutable::parse((string) $row->due_at, 'UTC'),
            submittedAtUtc: $row->submitted_at !== null ? CarbonImmutable::parse((string) $row->submitted_at, 'UTC') : null,
            ageDays: (int) $row->age_days,
        ));

        return new Paginator($rows, $result->total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'homeworkPage',
        ]);
    }

    public function freshness(ReportingPeriod $period): OperationsReportFreshnessData
    {
        return new OperationsReportFreshnessData(
            freshness: ReportDataFreshness::Live,
            generatedAt: CarbonImmutable::now(),
            reportingTimezone: $period->timezone,
            periodLabel: $period->label,
        );
    }

    public function canView(User $user): bool
    {
        $definition = $this->registry->find(self::REPORT_KEY);

        return $definition !== null
            && $this->access->canView($user, $definition)
            && $this->hasPermission($user, 'ViewLearningReports');
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function restrict(ReportFilters $filters): ReportFilters
    {
        $definition = $this->registry->find(self::REPORT_KEY);

        return $filters->restrictedTo($definition?->supportedFilters ?? []);
    }

    /** @throws AuthorizationException */
    private function authorize(User $user): void
    {
        if (! $this->canView($user)) {
            throw new AuthorizationException('You may not view learning analytics reporting.');
        }
    }

    private function hasPermission(User $user, string $permission): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /** Masked unless the viewer holds the explicit full-identity permission — same rule as Student Engagement. */
    private function personLabel(string $firstName, string $lastName, bool $canViewFullIdentity): string
    {
        if ($canViewFullIdentity) {
            return trim($firstName.' '.$lastName);
        }

        $first = trim($firstName);

        return $first === '' ? 'Student' : mb_substr($first, 0, 1).'***';
    }
}
