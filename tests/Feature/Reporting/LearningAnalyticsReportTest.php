<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Enums\LearningGoalStatus;
use App\Enums\LearningPlanMilestoneStatus;
use App\Enums\LearningPlanStatus;
use App\Enums\StudentStatus;
use App\Homework\Enums\HomeworkStatus;
use App\Models\AcademicCategory;
use App\Models\Booking;
use App\Models\HomeworkAssignment;
use App\Models\LearningPlanMilestone;
use App\Models\LearningPlanReview;
use App\Models\StudentLearningGoal;
use App\Models\StudentLearningPlan;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserProfile;
use App\Reporting\Contracts\FinancialReportsServiceInterface;
use App\Reporting\Contracts\LearningAnalyticsReportServiceInterface;
use App\Reporting\Contracts\MetricRegistryInterface;
use App\Reporting\Contracts\StudentEngagementReportServiceInterface;
use App\Reporting\DTOs\Learning\HomeworkAnalyticsData;
use App\Reporting\DTOs\Learning\HomeworkReviewRow;
use App\Reporting\DTOs\Learning\LearningPlanAnalyticsData;
use App\Reporting\DTOs\Learning\LearningPlanReviewRow;
use App\Reporting\DTOs\Learning\MilestoneReviewAnalyticsData;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Exceptions\UnsupportedReportFilterException;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Learning Analytics: terminology boundaries (§5),
 * provenance-gated metrics (§7), lifecycle counts, homework semantics,
 * milestone/review semantics, trends, filters, permissions, privacy,
 * zero side effects, Student Engagement reconciliation and performance bounds.
 */
class LearningAnalyticsReportTest extends TestCase
{
    use RefreshDatabase;

    private LearningAnalyticsReportServiceInterface $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->reports = app(LearningAnalyticsReportServiceInterface::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function manager(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    private function student(): User
    {
        $student = User::factory()->create(['status' => 'active', 'first_name' => 'Priya', 'last_name' => 'Sharma']);
        $student->assignRole('student');

        return $student;
    }

    private function instructor(): User
    {
        $instructor = User::factory()->create(['status' => 'active', 'first_name' => 'Rahul', 'last_name' => 'Verma']);
        $instructor->assignRole('instructor');

        return $instructor;
    }

    private function subject(string $name = 'Maths', string $slug = 'maths'): Subject
    {
        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);

        return Subject::query()->firstOrCreate(['slug' => $slug], ['academic_category_id' => $category->id, 'name' => $name, 'status' => 'active']);
    }

    /** @param array<string, mixed> $overrides */
    private function goal(User $student, array $overrides = []): StudentLearningGoal
    {
        return StudentLearningGoal::query()->create(array_merge([
            'user_id' => $student->id,
            'subject_id' => $this->subject()->id,
            'title' => 'Master algebra',
            'type' => 'academic',
            'status' => LearningGoalStatus::Active,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function plan(User $student, ?User $instructor = null, array $overrides = []): StudentLearningPlan
    {
        $createdAt = $overrides['created_at'] ?? null;
        unset($overrides['created_at']);

        if (! array_key_exists('learning_goal_id', $overrides)) {
            $overrides['learning_goal_id'] = $this->goal($student)->id;
        }

        $plan = StudentLearningPlan::query()->create(array_merge([
            'student_user_id' => $student->id,
            'primary_instructor_user_id' => $instructor?->id,
            'subject_id' => $this->subject()->id,
            'title' => 'Algebra plan',
            'status' => LearningPlanStatus::Active,
            'progress_percent' => 0,
            'started_at' => now()->subDays(5),
        ], $overrides));

        if ($createdAt !== null) {
            DB::table('student_learning_plans')->where('id', $plan->id)->update(['created_at' => $createdAt]);
        }

        return $plan;
    }

    /**
     * Every homework assignment must reference a
     * completed lesson (booking) or a learning plan. This reporting suite
     * only exercises homework status/date semantics and never asserts on
     * booking identity, so an independent completed booking is created for
     * context by default — mirroring HomeworkAssignmentFactory's own
     * default state — unless the caller already supplies one via overrides.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function homework(User $student, User $teacher, array $overrides = []): HomeworkAssignment
    {
        if (! array_key_exists('booking_id', $overrides) && ! array_key_exists('learning_plan_id', $overrides)) {
            $overrides['booking_id'] = Booking::factory()->completed()->create()->id;
        }

        return HomeworkAssignment::query()->create(array_merge([
            'teacher_id' => $teacher->id,
            'student_id' => $student->id,
            'subject' => 'Maths',
            'title' => 'Worksheet 1',
            'due_at' => now()->addDays(3),
            'status' => HomeworkStatus::Pending,
        ], $overrides));
    }

    private function period(): ReportingPeriod
    {
        return ReportingPeriod::forPreset(ReportingPeriodPreset::Last30Days, 'UTC');
    }

    private function filters(?ReportingPeriod $period = null): ReportFilters
    {
        return new ReportFilters(period: $period ?? $this->period());
    }

    // ── Terminology boundaries (§5) ───────────────────────────────────────

    public function test_a_learning_goal_is_never_counted_as_a_learning_plan(): void
    {
        $admin = $this->manager();
        $this->goal($this->student());

        $plans = $this->reports->planSummary($admin, $this->period(), $this->filters());
        $goals = $this->reports->goalSummary($admin, $this->period(), $this->filters());

        $this->assertSame(0, $plans->totalPlans);
        $this->assertSame(0, $plans->createdInPeriod);
        $this->assertSame(1, $goals->createdInPeriod);
        $this->assertSame(1, $goals->goalsWithoutPlans, 'A goal without a plan is a normal state, not an error.');
    }

    public function test_milestone_pending_and_skipped_are_never_achievements(): void
    {
        $admin = $this->manager();
        $plan = $this->plan($this->student());

        foreach ([LearningPlanMilestoneStatus::Pending, LearningPlanMilestoneStatus::InProgress, LearningPlanMilestoneStatus::Skipped] as $i => $status) {
            LearningPlanMilestone::query()->create([
                'learning_plan_id' => $plan->id,
                'title' => "M{$i}",
                'status' => $status,
                'sort_order' => $i,
                'completed_at' => $status === LearningPlanMilestoneStatus::Skipped ? null : null,
            ]);
        }

        LearningPlanMilestone::query()->create([
            'learning_plan_id' => $plan->id,
            'title' => 'Achieved',
            'status' => LearningPlanMilestoneStatus::Completed,
            'completed_at' => now()->subDay(),
            'sort_order' => 9,
        ]);

        $summary = $this->reports->milestoneReviewSummary($admin, $this->period(), $this->filters());

        $this->assertSame(1, $summary->milestonesAchievedInPeriod);
        $this->assertSame(1, $summary->currentMilestonesByStatus['skipped'] ?? 0);
        $this->assertSame(1, $summary->currentMilestonesByStatus['pending'] ?? 0);
    }

    public function test_homework_submission_is_not_completion_and_graded_has_no_period_metric(): void
    {
        $admin = $this->manager();
        $student = $this->student();
        $teacher = $this->instructor();

        $this->homework($student, $teacher, ['status' => HomeworkStatus::Submitted, 'submitted_at' => now()->subDay()]);
        $this->homework($student, $teacher, ['status' => HomeworkStatus::Graded, 'submitted_at' => now()->subDays(2)]);

        $summary = $this->reports->homeworkSummary($admin, $this->period(), $this->filters());

        $this->assertSame(2, $summary->submittedInPeriod);
        $this->assertSame(1, $summary->gradedCurrent, 'Graded is a current state, never inflated by submission.');
        $this->assertSame(1, $summary->currentByStatus['submitted'] ?? 0);
        // No period-scoped graded metric and no review-time metric may exist (no grading timestamp).
        $this->assertFalse(property_exists($summary, 'gradedInPeriod'));
        $this->assertFalse(property_exists($summary, 'averageReviewTime'));
    }

    public function test_no_consistency_progress_fabrication_or_ai_metric_exists(): void
    {
        $registry = app(MetricRegistryInterface::class);
        $keys = array_map(fn ($m) => $m->key, $registry->all());

        foreach ($keys as $key) {
            foreach (['consistency', 'risk_score', 'predicted', 'ai_', 'engagement_score', 'learning_score', 'curriculum_progress', 'resource_usage', 'review_time'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $key, "Metric key {$key} must not exist without an authoritative source (§7 gates).");
            }
        }

        $this->assertNull($registry->find('learning_plan_completion_rate'), 'No completion rate without a proven cohort denominator (§7.2).');
    }

    // ── Learning Plan lifecycle ───────────────────────────────────────────

    public function test_plan_lifecycle_counts_separate_current_state_from_period_events(): void
    {
        $admin = $this->manager();
        $student = $this->student();

        $this->plan($student, null, ['status' => LearningPlanStatus::Draft, 'started_at' => null]);
        $this->plan($student, null, ['status' => LearningPlanStatus::Active, 'started_at' => now()->subDays(3)]);
        $this->plan($student, null, ['status' => LearningPlanStatus::Completed, 'started_at' => now()->subDays(20), 'completed_at' => now()->subDays(2), 'progress_percent' => 100]);
        $this->plan($student, null, ['status' => LearningPlanStatus::Archived, 'started_at' => now()->subDays(60), 'archived_at' => now()->subDay()]);
        // Activated long before the period: current-state Active but NOT activated in period.
        $this->plan($student, null, ['status' => LearningPlanStatus::Active, 'started_at' => now()->subDays(90), 'created_at' => now()->subDays(90)]);

        $summary = $this->reports->planSummary($admin, $this->period(), $this->filters());

        $this->assertSame(5, $summary->totalPlans, 'Archived plans remain in historical totals.');
        $this->assertSame(2, $summary->currentByStatus['active'] ?? 0);
        $this->assertSame(1, $summary->currentByStatus['archived'] ?? 0);
        $this->assertSame(4, $summary->createdInPeriod);
        // Only the plans whose started_at falls inside the 30-day window count
        // (the archived plan activated 60 days ago; the old active plan 90).
        $this->assertSame(2, $summary->activatedInPeriod, 'Activation is a period event on started_at — current Active count is separate.');
        $this->assertSame(1, $summary->completedInPeriod);
        $this->assertSame(1, $summary->archivedInPeriod);
    }

    public function test_average_progress_uses_stored_domain_percent_and_is_null_without_active_plans(): void
    {
        $admin = $this->manager();
        $student = $this->student();

        $empty = $this->reports->planSummary($admin, $this->period(), $this->filters());
        $this->assertNull($empty->averageActiveProgressPercent, 'Never a fabricated 0% when no plan is Active.');

        $this->plan($student, null, ['progress_percent' => 40]);
        $this->plan($student, null, ['progress_percent' => 60]);
        // Completed plan progress never dilutes the active average.
        $this->plan($student, null, ['status' => LearningPlanStatus::Completed, 'completed_at' => now(), 'progress_percent' => 100]);

        $summary = $this->reports->planSummary($admin, $this->period(), $this->filters());
        $this->assertSame(50, $summary->averageActiveProgressPercent);
    }

    public function test_attention_conditions_are_source_backed_states(): void
    {
        $admin = $this->manager();
        $student = $this->student();

        $this->plan($student, null, ['target_completion_date' => now()->subDays(3)->toDateString()]); // active past target, no instructor
        $this->plan($student, $this->instructor(), ['target_completion_date' => now()->addMonth()->toDateString()]);

        $summary = $this->reports->planSummary($admin, $this->period(), $this->filters());

        $this->assertSame(1, $summary->activePlansPastTargetDate);
        $this->assertSame(1, $summary->activePlansWithoutInstructor);
    }

    // ── Learning Goals ────────────────────────────────────────────────────

    public function test_goal_lifecycle_linkage_and_multiple_active_goals(): void
    {
        $admin = $this->manager();
        $studentA = $this->student();
        $studentB = $this->student();

        $linked = $this->goal($studentA);
        $this->plan($studentA, null, ['learning_goal_id' => $linked->id]);
        $this->goal($studentA); // second active goal, unlinked
        $this->goal($studentB, ['status' => LearningGoalStatus::Completed, 'completed_at' => now()->subDay()]);
        $this->goal($studentB, ['status' => LearningGoalStatus::Archived, 'archived_at' => now()->subDays(2)]);

        $summary = $this->reports->goalSummary($admin, $this->period(), $this->filters());

        $this->assertSame(4, $summary->createdInPeriod);
        $this->assertSame(1, $summary->completedInPeriod);
        $this->assertSame(1, $summary->archivedInPeriod);
        $this->assertSame(1, $summary->goalsLinkedToPlans);
        $this->assertSame(3, $summary->goalsWithoutPlans);
        $this->assertSame(1, $summary->studentsWithMultipleActiveGoals);
    }

    // ── Homework semantics ────────────────────────────────────────────────

    public function test_overdue_uses_domain_predicate_and_is_current_state_only(): void
    {
        $admin = $this->manager();
        $student = $this->student();
        $teacher = $this->instructor();

        $this->homework($student, $teacher, ['due_at' => now()->subDays(2)]); // pending past due → overdue
        $this->homework($student, $teacher, ['due_at' => now()->addDay()]); // pending, not yet due
        // Submitted after due — late, but NEVER overdue now.
        $this->homework($student, $teacher, ['due_at' => now()->subDays(3), 'status' => HomeworkStatus::Submitted, 'submitted_at' => now()->subDay()]);
        // Graded past due — terminal, never overdue.
        $this->homework($student, $teacher, ['due_at' => now()->subDays(4), 'status' => HomeworkStatus::Graded, 'submitted_at' => now()->subDays(4)]);

        $summary = $this->reports->homeworkSummary($admin, $this->period(), $this->filters());

        $this->assertSame(1, $summary->currentlyOverdue);
        $this->assertSame(1, $summary->submittedLateInPeriod);
        $this->assertSame(4, $summary->assignedInPeriod);
    }

    public function test_submission_rates_use_elapsed_due_denominator_and_null_at_zero(): void
    {
        $admin = $this->manager();
        $student = $this->student();
        $teacher = $this->instructor();

        // No elapsed due dates yet → both rates null, never 0%.
        $this->homework($student, $teacher, ['due_at' => now()->addDays(2)]);
        $empty = $this->reports->homeworkSummary($admin, $this->period(), $this->filters());
        $this->assertNull($empty->submissionRate);
        $this->assertNull($empty->onTimeSubmissionRate);

        // Three elapsed-due assignments: on-time, late, never submitted.
        $this->homework($student, $teacher, ['due_at' => now()->subDays(2), 'status' => HomeworkStatus::Submitted, 'submitted_at' => now()->subDays(3)]);
        $this->homework($student, $teacher, ['due_at' => now()->subDays(2), 'status' => HomeworkStatus::Submitted, 'submitted_at' => now()->subDay()]);
        $this->homework($student, $teacher, ['due_at' => now()->subDays(2)]);

        $summary = $this->reports->homeworkSummary($admin, $this->period(), $this->filters());

        $this->assertSame(3, $summary->dueElapsedInPeriod);
        $this->assertEqualsWithDelta(66.7, $summary->submissionRate, 0.05);
        $this->assertEqualsWithDelta(33.3, $summary->onTimeSubmissionRate, 0.05);
    }

    public function test_assignment_count_and_submission_count_are_distinct_metrics(): void
    {
        $admin = $this->manager();
        $student = $this->student();
        $teacher = $this->instructor();

        // Assigned before the period, submitted inside it.
        $old = $this->homework($student, $teacher, ['due_at' => now()->subDays(10), 'status' => HomeworkStatus::Submitted, 'submitted_at' => now()->subDay()]);
        DB::table('homework_assignments')->where('id', $old->id)->update(['created_at' => now()->subDays(60)]);

        $summary = $this->reports->homeworkSummary($admin, $this->period(), $this->filters());

        $this->assertSame(0, $summary->assignedInPeriod, 'Assignment events use created_at.');
        $this->assertSame(1, $summary->submittedInPeriod, 'Submission events use submitted_at — never conflated.');
    }

    // ── Milestones & progress reviews ─────────────────────────────────────

    public function test_review_completed_uses_reviewed_at_and_review_due_is_a_plan_state(): void
    {
        $admin = $this->manager();
        $student = $this->student();
        $instructor = $this->instructor();

        $reviewed = $this->plan($student, $instructor);
        LearningPlanReview::query()->create([
            'learning_plan_id' => $reviewed->id,
            'student_user_id' => $student->id,
            'instructor_user_id' => $instructor->id,
            'review_number' => 1,
            'summary' => 'PRIVATE-NARRATIVE',
            'reviewed_at' => now()->subDay(),
        ]);
        LearningPlanReview::query()->create([
            'learning_plan_id' => $reviewed->id,
            'student_user_id' => $student->id,
            'instructor_user_id' => $instructor->id,
            'review_number' => 2,
            'reviewed_at' => now()->subHours(2),
        ]);

        $this->plan($student, $instructor, ['status' => LearningPlanStatus::ReviewDue]);

        $summary = $this->reports->milestoneReviewSummary($admin, $this->period(), $this->filters());

        $this->assertSame(2, $summary->reviewsCompletedInPeriod);
        $this->assertSame(1, $summary->plansReviewedInPeriod, 'Distinct plans, not review rows.');
        $this->assertSame(1, $summary->plansCurrentlyReviewDue);
    }

    public function test_active_plans_without_milestones_are_counted(): void
    {
        $admin = $this->manager();
        $student = $this->student();

        $with = $this->plan($student);
        LearningPlanMilestone::query()->create(['learning_plan_id' => $with->id, 'title' => 'M', 'status' => LearningPlanMilestoneStatus::Pending, 'sort_order' => 0]);
        $this->plan($student); // active, no milestones

        $summary = $this->reports->milestoneReviewSummary($admin, $this->period(), $this->filters());

        $this->assertSame(1, $summary->plansWithMilestones);
        $this->assertSame(1, $summary->activePlansWithoutMilestones);
    }

    // ── Trends ────────────────────────────────────────────────────────────

    public function test_trends_are_zero_filled_period_events(): void
    {
        $admin = $this->manager();
        $student = $this->student();
        $teacher = $this->instructor();

        $this->plan($student, null, ['started_at' => now()->subDays(2)]);
        $this->homework($student, $teacher, ['status' => HomeworkStatus::Submitted, 'submitted_at' => now()->subDays(2)]);

        $trends = $this->reports->trends($admin, $this->period(), $this->filters());

        $this->assertCount(30, $trends->plansActivated, 'Every day of the period is present, empty days zero.');
        $this->assertSame(1, array_sum($trends->plansActivated));
        $this->assertSame(1, array_sum($trends->homeworkSubmitted));
        $this->assertSame(0, array_sum($trends->plansCompleted));
        $key = now()->subDays(2)->toDateString();
        $this->assertSame(1, $trends->plansActivated[$key] ?? null);
    }

    // ── Filters ───────────────────────────────────────────────────────────

    public function test_plan_status_and_homework_status_filters_scope_results(): void
    {
        $admin = $this->manager();
        $student = $this->student();
        $teacher = $this->instructor();

        $this->plan($student, null, ['status' => LearningPlanStatus::Active]);
        $this->plan($student, null, ['status' => LearningPlanStatus::Paused]);
        $this->homework($student, $teacher);
        $this->homework($student, $teacher, ['status' => HomeworkStatus::Submitted, 'submitted_at' => now()->subDay()]);

        $planFiltered = $this->reports->planSummary($admin, $this->period(), new ReportFilters(
            period: $this->period(),
            learningPlanStatus: LearningPlanStatus::Paused,
        ));
        $this->assertSame(1, $planFiltered->totalPlans);

        $homeworkFiltered = $this->reports->homeworkSummary($admin, $this->period(), new ReportFilters(
            period: $this->period(),
            homeworkStatus: HomeworkStatus::Submitted,
        ));
        $this->assertSame(1, $homeworkFiltered->assignedInPeriod);
    }

    public function test_unknown_academic_filter_enum_values_fail_loud(): void
    {
        $this->expectException(UnsupportedReportFilterException::class);

        ReportFilters::fromSafeArray($this->period(), ['learning_plan_status' => 'nonsense_status']);
    }

    public function test_subject_filter_scopes_plans_and_goals(): void
    {
        $admin = $this->manager();
        $student = $this->student();
        $maths = $this->subject();
        $physics = $this->subject('Physics', 'physics');

        $this->plan($student, null, ['subject_id' => $maths->id]);
        $this->plan($student, null, ['subject_id' => $physics->id]);

        $filtered = $this->reports->planSummary($admin, $this->period(), new ReportFilters(
            period: $this->period(),
            subjectId: (string) $physics->id,
        ));

        $this->assertSame(1, $filtered->totalPlans);
    }

    // ── Permissions ───────────────────────────────────────────────────────

    public function test_learning_reports_require_the_learning_permission(): void
    {
        $admin = $this->manager();
        Role::findByName('manager', 'web')->revokePermissionTo('ViewLearningReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->expectException(AuthorizationException::class);
        $this->reports->planSummary($admin, $this->period(), $this->filters());
    }

    public function test_learning_access_never_grants_finance_access(): void
    {
        $admin = $this->manager();
        foreach (['ViewFinanceReports', 'ViewWalletReports', 'ViewPaymentReports', 'ViewInstructorCompensationReports'] as $permission) {
            Role::findByName('manager', 'web')->revokePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Learning still works…
        $this->reports->planSummary($admin, $this->period(), $this->filters());

        // …finance does not.
        $this->expectException(AuthorizationException::class);
        app(FinancialReportsServiceInterface::class)->walletSummary($admin, $this->period(), $this->filters());
    }

    public function test_student_identity_is_masked_without_the_full_identity_permission(): void
    {
        $admin = $this->manager();
        Role::findByName('manager', 'web')->revokePermissionTo('ViewStudentReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->plan($this->student());

        $rows = $this->reports->planReviewTable($admin, $this->period(), $this->filters());

        $this->assertCount(1, $rows->items());
        $this->assertSame('P***', $rows->items()[0]->studentLabel);
        $this->assertStringNotContainsString('Sharma', $rows->items()[0]->studentLabel);
    }

    public function test_plan_drill_down_requires_the_existing_plan_view_permission(): void
    {
        $admin = $this->manager();
        $this->plan($this->student());

        $withPermission = $this->reports->planReviewTable($admin, $this->period(), $this->filters());
        $first = $withPermission->items()[0];

        // Whatever the manager policy grants, a null URL must render as plain text; verify the gate is consulted
        // by checking the two states are consistent with the Gate itself.
        $expected = Gate::forUser($admin)->allows('viewAny', StudentLearningPlan::class);
        $this->assertSame($expected, $first->drillDownUrl !== null);
    }

    // ── Privacy ───────────────────────────────────────────────────────────

    public function test_dtos_structurally_exclude_private_academic_content(): void
    {
        foreach ([
            LearningPlanReviewRow::class,
            HomeworkReviewRow::class,
            LearningPlanAnalyticsData::class,
            HomeworkAnalyticsData::class,
            MilestoneReviewAnalyticsData::class,
        ] as $dto) {
            $properties = array_map(
                fn (\ReflectionProperty $p) => strtolower($p->getName()),
                (new \ReflectionClass($dto))->getProperties(),
            );

            foreach (['email', 'phone', 'dob', 'address', 'submissiontext', 'feedback', 'progressnotes', 'challenges', 'recommendations', 'summary', 'notes', 'wallet', 'payment', 'amount'] as $forbidden) {
                foreach ($properties as $property) {
                    $this->assertStringNotContainsString($forbidden, $property, "{$dto}::\${$property} must not carry private/financial content.");
                }
            }

            // The literal grade value is private ('gradedCurrent' is a count, not a grade).
            $this->assertNotContains('grade', $properties, "{$dto} must never carry a grade value.");
        }
    }

    public function test_review_narrative_never_reaches_report_output(): void
    {
        $admin = $this->manager();
        $student = $this->student();
        $plan = $this->plan($student, $this->instructor());

        LearningPlanReview::query()->create([
            'learning_plan_id' => $plan->id,
            'student_user_id' => $student->id,
            'review_number' => 1,
            'summary' => 'SECRET-NARRATIVE-XYZ',
            'progress_notes' => 'SECRET-NOTES-XYZ',
            'reviewed_at' => now()->subDay(),
        ]);

        $rows = $this->reports->planReviewTable($admin, $this->period(), $this->filters());
        $serialized = json_encode($rows->items());

        $this->assertStringNotContainsString('SECRET-NARRATIVE-XYZ', $serialized);
        $this->assertStringNotContainsString('SECRET-NOTES-XYZ', $serialized);
    }

    public function test_homework_submission_content_never_reaches_report_output(): void
    {
        $admin = $this->manager();
        $this->homework($this->student(), $this->instructor(), [
            'status' => HomeworkStatus::Submitted,
            'submitted_at' => now()->subDay(),
            'submission_text' => 'PRIVATE-SUBMISSION-BODY',
            'feedback' => 'PRIVATE-FEEDBACK',
            'grade' => 'A+',
        ]);

        $rows = $this->reports->homeworkAttentionTable($admin, $this->period(), $this->filters());
        $serialized = json_encode($rows->items());

        $this->assertStringNotContainsString('PRIVATE-SUBMISSION-BODY', $serialized);
        $this->assertStringNotContainsString('PRIVATE-FEEDBACK', $serialized);
        $this->assertStringNotContainsString('A+', $serialized);
    }

    // ── Zero side effects (§27) ───────────────────────────────────────────

    public function test_rendering_every_learning_summary_mutates_nothing(): void
    {
        Http::fake();
        $admin = $this->manager();
        $student = $this->student();
        $teacher = $this->instructor();

        $plan = $this->plan($student, $teacher);
        $this->homework($student, $teacher, ['due_at' => now()->subDay()]);
        LearningPlanMilestone::query()->create(['learning_plan_id' => $plan->id, 'title' => 'M', 'status' => LearningPlanMilestoneStatus::Pending, 'sort_order' => 0]);

        $before = [
            DB::table('student_learning_plans')->orderBy('id')->get(['id', 'status', 'progress_percent', 'updated_at'])->toJson(),
            DB::table('student_learning_goals')->orderBy('id')->get(['id', 'status', 'updated_at'])->toJson(),
            DB::table('homework_assignments')->orderBy('id')->get(['id', 'status', 'submitted_at'])->toJson(),
            DB::table('learning_plan_milestones')->orderBy('id')->get(['id', 'status', 'completed_at'])->toJson(),
        ];
        $auditBefore = DB::table('activity_log')->count();
        $jobsBefore = DB::table('jobs')->count();

        $this->reports->planSummary($admin, $this->period(), $this->filters());
        $this->reports->goalSummary($admin, $this->period(), $this->filters());
        $this->reports->homeworkSummary($admin, $this->period(), $this->filters());
        $this->reports->milestoneReviewSummary($admin, $this->period(), $this->filters());
        $this->reports->trends($admin, $this->period(), $this->filters());
        $this->reports->planReviewTable($admin, $this->period(), $this->filters());
        $this->reports->homeworkAttentionTable($admin, $this->period(), $this->filters());

        $after = [
            DB::table('student_learning_plans')->orderBy('id')->get(['id', 'status', 'progress_percent', 'updated_at'])->toJson(),
            DB::table('student_learning_goals')->orderBy('id')->get(['id', 'status', 'updated_at'])->toJson(),
            DB::table('homework_assignments')->orderBy('id')->get(['id', 'status', 'submitted_at'])->toJson(),
            DB::table('learning_plan_milestones')->orderBy('id')->get(['id', 'status', 'completed_at'])->toJson(),
        ];

        $this->assertSame($before, $after, 'Reporting must never mutate academic state.');
        $this->assertSame($auditBefore, DB::table('activity_log')->count(), 'Ordinary report views write no audit rows.');
        $this->assertSame($jobsBefore, DB::table('jobs')->count(), 'Reporting enqueues nothing.');
        Http::assertNothingSent();
    }

    // ── Student Engagement reconciliation (§23) ────────────────────────────────────

    public function test_active_plan_definition_matches_phase_18d_student_engagement(): void
    {
        $admin = $this->manager();
        $student = $this->student();
        UserProfile::updateOrCreate(['user_id' => $student->id], ['student_status' => StudentStatus::Active]);

        $this->plan($student, null, ['status' => LearningPlanStatus::Active]);
        $this->plan($student, null, ['status' => LearningPlanStatus::Paused]);
        $this->plan($student, null, ['status' => LearningPlanStatus::ReviewDue]);

        // 18F current-state Active count…
        $learning = $this->reports->planSummary($admin, $this->period(), $this->filters());
        $activePlans18F = $learning->currentByStatus['active'] ?? 0;

        // …must use the exact 18D predicate: strictly status = 'active'.
        $engagement = app(StudentEngagementReportServiceInterface::class)->summary($admin, $this->period(), $this->filters());

        $this->assertSame(1, $activePlans18F);
        $this->assertSame(1, $engagement->withActiveLearningPlans, 'Both phases share one authoritative "active plan" definition.');
    }

    // ── Performance ───────────────────────────────────────────────────────

    public function test_summary_query_count_is_bounded(): void
    {
        $admin = $this->manager();
        $student = $this->student();
        $this->plan($student, $this->instructor());
        $this->homework($student, $this->instructor());

        DB::enableQueryLog();
        $this->reports->planSummary($admin, $this->period(), $this->filters());
        $this->reports->goalSummary($admin, $this->period(), $this->filters());
        $this->reports->homeworkSummary($admin, $this->period(), $this->filters());
        $this->reports->milestoneReviewSummary($admin, $this->period(), $this->filters());
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 42 today: grouped aggregates + settings/permission lookups; bound guards against N+1 regressions.
        $this->assertLessThanOrEqual(45, $count, 'Aggregate summaries must stay a bounded set of grouped queries.');
    }

    public function test_table_query_count_is_constant_as_rows_grow(): void
    {
        $admin = $this->manager();
        $teacher = $this->instructor();

        for ($i = 0; $i < 8; $i++) {
            $student = $this->student();
            $this->plan($student, $teacher);
            $this->homework($student, $teacher, ['due_at' => now()->subDay()]);
        }

        DB::enableQueryLog();
        $this->reports->planReviewTable($admin, $this->period(), $this->filters());
        $planQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->reports->homeworkAttentionTable($admin, $this->period(), $this->filters());
        $homeworkQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(8, $planQueries, 'Plan table must not grow queries with rows (no N+1).');
        $this->assertLessThanOrEqual(6, $homeworkQueries, 'Homework table must not grow queries with rows (no N+1).');
    }
}
