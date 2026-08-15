<?php

declare(strict_types=1);

namespace App\Reporting\Repositories;

use App\Enums\LearningGoalStatus;
use App\Enums\LearningPlanMilestoneStatus;
use App\Enums\LearningPlanStatus;
use App\Reporting\DTOs\Learning\LearningGoalAnalyticsData;
use App\Reporting\DTOs\Learning\LearningPlanAnalyticsData;
use App\Reporting\DTOs\Learning\MilestoneReviewAnalyticsData;
use App\Reporting\DTOs\Operations\LabeledCountRow;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Support\LocalDaySql;
use App\Reporting\ValueObjects\ReportingPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only Learning Plan / Goal / Milestone / Progress Review
 * aggregates. All figures are SQL aggregation over the
 * authoritative lifecycle columns; soft-deleted rows are excluded;
 * archived plans and goals remain in every historical count. Reuses
 * the exact Student Engagement predicate for "active plan" (status = 'active'
 * only) so Student Engagement and Learning Analytics can never
 * disagree on the shared fact. Never touches private note columns.
 */
final class LearningPlanAnalyticsRepository
{
    public function planSummary(ReportingPeriod $period, ReportFilters $filters): LearningPlanAnalyticsData
    {
        $byStatus = $this->plans($filters)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();

        $avg = $this->plans($filters)
            ->where('status', LearningPlanStatus::Active->value)
            ->selectRaw('AVG(progress_percent) as avg_progress, count(*) as aggregate')
            ->first();

        return new LearningPlanAnalyticsData(
            totalPlans: array_sum($byStatus),
            currentByStatus: $byStatus,
            createdInPeriod: $this->countPlansInPeriod('created_at', $period, $filters),
            activatedInPeriod: $this->countPlansInPeriod('started_at', $period, $filters),
            completedInPeriod: $this->countPlansInPeriod('completed_at', $period, $filters),
            archivedInPeriod: $this->countPlansInPeriod('archived_at', $period, $filters),
            averageActiveProgressPercent: ((int) ($avg->aggregate ?? 0)) > 0 ? (int) round((float) $avg->avg_progress) : null,
            activePlansPastTargetDate: (int) $this->plans($filters)
                ->where('status', LearningPlanStatus::Active->value)
                ->whereNotNull('target_completion_date')
                ->where('target_completion_date', '<', now()->toDateString())
                ->count(),
            activePlansWithoutInstructor: (int) $this->plans($filters)
                ->where('status', LearningPlanStatus::Active->value)
                ->whereNull('primary_instructor_user_id')
                ->count(),
            bySubject: $this->planBreakdown($filters, 'subjects', 'subject_id', 'No subject'),
            byEducationLevel: $this->planBreakdown($filters, 'academic_levels', 'academic_level_id', 'No level'),
            byInstructor: $this->plansByInstructor($filters),
        );
    }

    public function goalSummary(ReportingPeriod $period, ReportFilters $filters): LearningGoalAnalyticsData
    {
        $byStatus = $this->goals($filters)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();

        return new LearningGoalAnalyticsData(
            totalGoals: array_sum($byStatus),
            currentByStatus: $byStatus,
            createdInPeriod: $this->countGoalsInPeriod('created_at', $period, $filters),
            completedInPeriod: $this->countGoalsInPeriod('completed_at', $period, $filters),
            archivedInPeriod: $this->countGoalsInPeriod('archived_at', $period, $filters),
            byType: $this->goals($filters)
                ->selectRaw('type, count(*) as aggregate')
                ->groupBy('type')
                ->pluck('aggregate', 'type')
                ->map(fn ($v) => (int) $v)
                ->all(),
            goalsLinkedToPlans: (int) $this->goals($filters)
                ->whereExists(fn (Builder $q) => $q
                    ->from('student_learning_plans')
                    ->whereColumn('student_learning_plans.learning_goal_id', 'student_learning_goals.id')
                    ->whereNull('student_learning_plans.deleted_at'))
                ->count(),
            goalsWithoutPlans: (int) $this->goals($filters)
                ->whereNotExists(fn (Builder $q) => $q
                    ->from('student_learning_plans')
                    ->whereColumn('student_learning_plans.learning_goal_id', 'student_learning_goals.id')
                    ->whereNull('student_learning_plans.deleted_at'))
                ->count(),
            studentsWithMultipleActiveGoals: (int) DB::table(
                $this->goals($filters)
                    ->where('status', LearningGoalStatus::Active->value)
                    ->selectRaw('user_id')
                    ->groupBy('user_id')
                    ->havingRaw('count(*) > 1'),
                'multi',
            )->count(),
            bySubject: $this->goalBreakdown($filters, 'subjects', 'subject_id', 'No subject'),
            byEducationLevel: $this->goalBreakdown($filters, 'academic_levels', 'academic_level_id', 'No level'),
        );
    }

    public function milestoneReviewSummary(ReportingPeriod $period, ReportFilters $filters): MilestoneReviewAnalyticsData
    {
        return new MilestoneReviewAnalyticsData(
            milestonesAchievedInPeriod: (int) $this->milestones($filters)
                ->where('learning_plan_milestones.status', LearningPlanMilestoneStatus::Completed->value)
                ->where('learning_plan_milestones.completed_at', '>=', $period->startUtc)
                ->where('learning_plan_milestones.completed_at', '<', $period->endUtcExclusive)
                ->count(),
            currentMilestonesByStatus: $this->milestones($filters)
                ->selectRaw('learning_plan_milestones.status, count(*) as aggregate')
                ->groupBy('learning_plan_milestones.status')
                ->pluck('aggregate', 'status')
                ->map(fn ($v) => (int) $v)
                ->all(),
            plansWithMilestones: (int) $this->plans($filters)
                ->whereExists(fn (Builder $q) => $q
                    ->from('learning_plan_milestones')
                    ->whereColumn('learning_plan_milestones.learning_plan_id', 'student_learning_plans.id')
                    ->whereNull('learning_plan_milestones.deleted_at'))
                ->count(),
            activePlansWithoutMilestones: (int) $this->plans($filters)
                ->where('status', LearningPlanStatus::Active->value)
                ->whereNotExists(fn (Builder $q) => $q
                    ->from('learning_plan_milestones')
                    ->whereColumn('learning_plan_milestones.learning_plan_id', 'student_learning_plans.id')
                    ->whereNull('learning_plan_milestones.deleted_at'))
                ->count(),
            reviewsCompletedInPeriod: (int) $this->reviews($filters)
                ->whereNotNull('learning_plan_reviews.reviewed_at')
                ->where('learning_plan_reviews.reviewed_at', '>=', $period->startUtc)
                ->where('learning_plan_reviews.reviewed_at', '<', $period->endUtcExclusive)
                ->count(),
            plansCurrentlyReviewDue: (int) $this->plans($filters)
                ->where('status', LearningPlanStatus::ReviewDue->value)
                ->count(),
            plansReviewedInPeriod: (int) $this->reviews($filters)
                ->whereNotNull('learning_plan_reviews.reviewed_at')
                ->where('learning_plan_reviews.reviewed_at', '>=', $period->startUtc)
                ->where('learning_plan_reviews.reviewed_at', '<', $period->endUtcExclusive)
                ->distinct()
                ->count('learning_plan_reviews.learning_plan_id'),
        );
    }

    /**
     * Daily period-event trend on one authoritative timestamp column of
     * `student_learning_plans` — fixed-offset bucketing, zero-filled
     * (same documented DST limitation as the 18D registration trend).
     *
     * @return array<string, int>
     */
    public function planTrend(string $column, ReportingPeriod $period, ReportFilters $filters): array
    {
        [$dayExpression, $dayBindings] = LocalDaySql::dateExpression("`{$column}`", $period);

        $rows = $this->plans($filters)
            ->whereNotNull($column)
            ->where($column, '>=', $period->startUtc)
            ->where($column, '<', $period->endUtcExclusive)
            ->selectRaw($dayExpression.' as day, count(*) as aggregate', $dayBindings)
            ->groupBy('day')
            ->pluck('aggregate', 'day');

        return $this->zeroFill($rows->all(), $period);
    }

    /** @return array<string, int> milestones achieved per day (completed_at). */
    public function milestoneAchievedTrend(ReportingPeriod $period, ReportFilters $filters): array
    {
        [$dayExpression, $dayBindings] = LocalDaySql::dateExpression('learning_plan_milestones.completed_at', $period);

        $rows = $this->milestones($filters)
            ->where('learning_plan_milestones.status', LearningPlanMilestoneStatus::Completed->value)
            ->whereNotNull('learning_plan_milestones.completed_at')
            ->where('learning_plan_milestones.completed_at', '>=', $period->startUtc)
            ->where('learning_plan_milestones.completed_at', '<', $period->endUtcExclusive)
            ->selectRaw($dayExpression.' as day, count(*) as aggregate', $dayBindings)
            ->groupBy('day')
            ->pluck('aggregate', 'day');

        return $this->zeroFill($rows->all(), $period);
    }

    /** @return array<string, int> progress reviews completed per day (reviewed_at). */
    public function reviewCompletedTrend(ReportingPeriod $period, ReportFilters $filters): array
    {
        [$dayExpression, $dayBindings] = LocalDaySql::dateExpression('learning_plan_reviews.reviewed_at', $period);

        $rows = $this->reviews($filters)
            ->whereNotNull('learning_plan_reviews.reviewed_at')
            ->where('learning_plan_reviews.reviewed_at', '>=', $period->startUtc)
            ->where('learning_plan_reviews.reviewed_at', '<', $period->endUtcExclusive)
            ->selectRaw($dayExpression.' as day, count(*) as aggregate', $dayBindings)
            ->groupBy('day')
            ->pluck('aggregate', 'day');

        return $this->zeroFill($rows->all(), $period);
    }

    /**
     * Bounded plan review table — attention-first ordering (review due,
     * then target passed, then most recently started), constant query
     * count via aggregate subselects. Returns raw rows; the service
     * masks identity and builds DTOs.
     *
     * @return object{total: int, rows: Collection<int, object>}
     */
    public function planReviewRows(ReportFilters $filters, int $page, int $perPage): object
    {
        $base = $this->plans($filters)
            ->whereIn('student_learning_plans.status', [
                LearningPlanStatus::Active->value,
                LearningPlanStatus::ReviewDue->value,
                LearningPlanStatus::Paused->value,
                LearningPlanStatus::AwaitingAssessment->value,
            ]);

        $total = (clone $base)->count();

        $rows = $base
            ->join('users as students', 'students.id', '=', 'student_learning_plans.student_user_id')
            ->leftJoin('users as instructors', 'instructors.id', '=', 'student_learning_plans.primary_instructor_user_id')
            ->leftJoin('subjects', 'subjects.id', '=', 'student_learning_plans.subject_id')
            ->orderByRaw("student_learning_plans.status = 'review_due' desc")
            ->orderByRaw('(student_learning_plans.target_completion_date is not null and student_learning_plans.target_completion_date < curdate()) desc')
            ->orderByDesc('student_learning_plans.started_at')
            ->orderBy('student_learning_plans.id')
            ->forPage($page, $perPage)
            ->get([
                'student_learning_plans.id',
                'student_learning_plans.status',
                'student_learning_plans.progress_percent',
                'student_learning_plans.started_at',
                'student_learning_plans.target_completion_date',
                'student_learning_plans.primary_instructor_user_id',
                'students.first_name as student_first_name',
                'students.last_name as student_last_name',
                'instructors.first_name as instructor_first_name',
                'instructors.last_name as instructor_last_name',
                'subjects.name as subject_name',
                DB::raw('(select count(*) from learning_plan_milestones m where m.learning_plan_id = student_learning_plans.id and m.deleted_at is null) as milestones_total'),
                DB::raw("(select count(*) from learning_plan_milestones m where m.learning_plan_id = student_learning_plans.id and m.deleted_at is null and m.status = 'completed') as milestones_achieved"),
                DB::raw('(select max(r.reviewed_at) from learning_plan_reviews r where r.learning_plan_id = student_learning_plans.id) as last_review_at'),
            ]);

        return (object) ['total' => $total, 'rows' => $rows];
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function plans(ReportFilters $filters): Builder
    {
        $query = DB::table('student_learning_plans')->whereNull('student_learning_plans.deleted_at');

        if ($filters->subjectId !== null) {
            $query->where('student_learning_plans.subject_id', $filters->subjectId);
        }

        if ($filters->educationLevelId !== null) {
            $query->where('student_learning_plans.academic_level_id', $filters->educationLevelId);
        }

        if ($filters->instructorId !== null) {
            $query->where('student_learning_plans.primary_instructor_user_id', $filters->instructorId);
        }

        if ($filters->studentId !== null) {
            $query->where('student_learning_plans.student_user_id', $filters->studentId);
        }

        if ($filters->learningPlanStatus !== null) {
            $query->where('student_learning_plans.status', $filters->learningPlanStatus->value);
        }

        return $query;
    }

    private function goals(ReportFilters $filters): Builder
    {
        $query = DB::table('student_learning_goals')->whereNull('student_learning_goals.deleted_at');

        if ($filters->subjectId !== null) {
            $query->where('student_learning_goals.subject_id', $filters->subjectId);
        }

        if ($filters->educationLevelId !== null) {
            $query->where('student_learning_goals.academic_level_id', $filters->educationLevelId);
        }

        if ($filters->studentId !== null) {
            $query->where('student_learning_goals.user_id', $filters->studentId);
        }

        if ($filters->learningGoalStatus !== null) {
            $query->where('student_learning_goals.status', $filters->learningGoalStatus->value);
        }

        return $query;
    }

    /** Milestones joined through their plan so plan-level filters apply. */
    private function milestones(ReportFilters $filters): Builder
    {
        return $this->plans($filters)
            ->join('learning_plan_milestones', 'learning_plan_milestones.learning_plan_id', '=', 'student_learning_plans.id')
            ->whereNull('learning_plan_milestones.deleted_at');
    }

    /** Reviews joined through their plan so plan-level filters apply. */
    private function reviews(ReportFilters $filters): Builder
    {
        return $this->plans($filters)
            ->join('learning_plan_reviews', 'learning_plan_reviews.learning_plan_id', '=', 'student_learning_plans.id');
    }

    private function countPlansInPeriod(string $column, ReportingPeriod $period, ReportFilters $filters): int
    {
        return (int) $this->plans($filters)
            ->whereNotNull($column)
            ->where($column, '>=', $period->startUtc)
            ->where($column, '<', $period->endUtcExclusive)
            ->count();
    }

    private function countGoalsInPeriod(string $column, ReportingPeriod $period, ReportFilters $filters): int
    {
        return (int) $this->goals($filters)
            ->whereNotNull($column)
            ->where($column, '>=', $period->startUtc)
            ->where($column, '<', $period->endUtcExclusive)
            ->count();
    }

    /** @return list<LabeledCountRow> current plans per label; rows with a missing relation keep a neutral placeholder. */
    private function planBreakdown(ReportFilters $filters, string $table, string $fk, string $placeholder): array
    {
        return $this->plans($filters)
            ->leftJoin($table, "{$table}.id", '=', "student_learning_plans.{$fk}")
            ->selectRaw("COALESCE({$table}.name, ?) as label, count(*) as aggregate", [$placeholder])
            ->groupBy('label')
            ->orderByDesc('aggregate')
            ->limit(20)
            ->get()
            ->map(fn ($row) => new LabeledCountRow((string) $row->label, (int) $row->aggregate))
            ->all();
    }

    /** @return list<LabeledCountRow> */
    private function goalBreakdown(ReportFilters $filters, string $table, string $fk, string $placeholder): array
    {
        return $this->goals($filters)
            ->leftJoin($table, "{$table}.id", '=', "student_learning_goals.{$fk}")
            ->selectRaw("COALESCE({$table}.name, ?) as label, count(*) as aggregate", [$placeholder])
            ->groupBy('label')
            ->orderByDesc('aggregate')
            ->limit(20)
            ->get()
            ->map(fn ($row) => new LabeledCountRow((string) $row->label, (int) $row->aggregate))
            ->all();
    }

    /** @return list<LabeledCountRow> current plans per instructor display name (archived instructors retained via their user row). */
    private function plansByInstructor(ReportFilters $filters): array
    {
        return $this->plans($filters)
            ->leftJoin('users', 'users.id', '=', 'student_learning_plans.primary_instructor_user_id')
            ->selectRaw("COALESCE(CONCAT(users.first_name, ' ', users.last_name), 'No instructor assigned') as label, count(*) as aggregate")
            ->groupBy('label')
            ->orderByDesc('aggregate')
            ->limit(20)
            ->get()
            ->map(fn ($row) => new LabeledCountRow((string) $row->label, (int) $row->aggregate))
            ->all();
    }

    /** @return array<string, int> */
    private function zeroFill(array $rows, ReportingPeriod $period): array
    {
        $result = [];
        $cursor = $period->start;

        while ($cursor->lt($period->end)) {
            $key = $cursor->toDateString();
            $result[$key] = (int) ($rows[$key] ?? 0);
            $cursor = $cursor->addDay();
        }

        return $result;
    }
}
