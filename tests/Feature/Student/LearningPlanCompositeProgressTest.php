<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Enums\InstructorStatus;
use App\Enums\LearningPlanMilestoneStatus;
use App\Enums\LearningPlanStatus;
use App\Enums\StudentStatus;
use App\Homework\Enums\HomeworkStatus;
use App\Homework\Services\HomeworkService;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\HomeworkAssignment;
use App\Models\LearningPlanMilestone;
use App\Models\StudentLearningGoal;
use App\Models\StudentLearningPlan;
use App\Models\Subject;
use App\Models\User;
use App\Services\Student\LearningPlanProgressCalculator;
use App\Services\Student\LearningPlanProgressService;
use App\Services\Student\LearningPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS §6.17-5: learning-plan progress must average every
 * SRS evidence domain the schema can reliably attribute to exactly
 * one plan — currently milestones and directly-linked homework.
 * Lessons and periodic reviews are documented, tested exclusions (see
 * LearningPlanProgressCalculator's docblock) — this file proves they
 * never silently contribute, rather than pretending to test support
 * that does not exist in the data model yet.
 */
class LearningPlanCompositeProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $instructor;

    private Subject $subject;

    private AcademicLevel $level;

    private StudentLearningGoal $goal;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['student', 'instructor', 'manager'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');
        $this->student->profile()->update(['student_status' => StudentStatus::Active]);

        $this->instructor = $this->makeInstructor();

        $category = AcademicCategory::create(['name' => 'Mathematics', 'slug' => 'mathematics']);
        $this->subject = Subject::create(['academic_category_id' => $category->id, 'name' => 'Algebra', 'slug' => 'algebra']);
        $this->level = AcademicLevel::create(['name' => 'High School', 'slug' => 'high-school']);

        $this->goal = StudentLearningGoal::create([
            'user_id' => $this->student->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'title' => 'Master algebra',
            'type' => 'academic',
            'status' => 'active',
            'created_by' => $this->student->id,
            'updated_by' => $this->student->id,
        ]);
    }

    // ── Calculation scenarios ─────────────────────────────────────────

    public function test_plan_with_no_applicable_evidence_returns_zero(): void
    {
        $plan = $this->assignedPlan();

        $this->assertSame(0, $this->calculate($plan));
    }

    public function test_milestone_only_plan_remains_correctly_supported(): void
    {
        $plan = $this->assignedPlan();
        $this->milestone($plan, LearningPlanMilestoneStatus::Completed);
        $this->milestone($plan, LearningPlanMilestoneStatus::Pending);

        $this->assertSame(50, $this->calculate($plan));
    }

    public function test_homework_only_plan_calculates_correctly(): void
    {
        $plan = $this->assignedPlan();
        $this->homework($plan, HomeworkStatus::Graded);
        $this->homework($plan, HomeworkStatus::Submitted);

        $this->assertSame(50, $this->calculate($plan));
    }

    public function test_mixed_evidence_produces_the_documented_composite(): void
    {
        $plan = $this->assignedPlan();
        // Milestones: 2/2 complete = 100%.
        $this->milestone($plan, LearningPlanMilestoneStatus::Completed);
        $this->milestone($plan, LearningPlanMilestoneStatus::Completed);
        // Homework: 0/2 graded = 0%.
        $this->homework($plan, HomeworkStatus::Pending);
        $this->homework($plan, HomeworkStatus::Submitted);

        // Equal-weight average of the two applicable domains: (100 + 0) / 2 = 50.
        $this->assertSame(50, $this->calculate($plan));
    }

    public function test_an_empty_domain_is_excluded_from_the_denominator_not_penalized_as_zero(): void
    {
        $plan = $this->assignedPlan();
        // Only milestones present — homework domain has zero applicable
        // records and must be excluded entirely, not averaged in as 0%.
        $this->milestone($plan, LearningPlanMilestoneStatus::Completed);

        $this->assertSame(100, $this->calculate($plan));
    }

    public function test_partial_completion_within_one_domain_is_calculated_correctly(): void
    {
        $plan = $this->assignedPlan();
        $this->milestone($plan, LearningPlanMilestoneStatus::Completed);
        $this->milestone($plan, LearningPlanMilestoneStatus::Pending);
        $this->milestone($plan, LearningPlanMilestoneStatus::Pending);

        $this->assertSame(33, $this->calculate($plan));
    }

    public function test_result_is_deterministically_rounded(): void
    {
        $plan = $this->assignedPlan();
        $this->milestone($plan, LearningPlanMilestoneStatus::Completed);
        $this->milestone($plan, LearningPlanMilestoneStatus::Completed);
        $this->milestone($plan, LearningPlanMilestoneStatus::Pending);

        // 2/3 = 66.67% → rounds to 67, not truncated to 66.
        $this->assertSame(67, $this->calculate($plan));
    }

    public function test_result_never_exceeds_100_or_falls_below_0(): void
    {
        $plan = $this->assignedPlan();
        $this->milestone($plan, LearningPlanMilestoneStatus::Completed);
        $this->assertSame(100, $this->calculate($plan));

        $other = $this->assignedPlan($this->newGoal('Second goal'));
        $this->milestone($other, LearningPlanMilestoneStatus::Pending);
        $this->assertSame(0, $this->calculate($other));
    }

    public function test_soft_deleted_milestones_and_homework_do_not_contribute(): void
    {
        $plan = $this->assignedPlan();
        $completed = $this->milestone($plan, LearningPlanMilestoneStatus::Completed);
        $pending = $this->milestone($plan, LearningPlanMilestoneStatus::Pending);
        $pending->delete();

        // Only the one non-deleted, completed milestone remains applicable.
        $this->assertSame(100, $this->calculate($plan));

        $completed->delete();
        $graded = $this->homework($plan, HomeworkStatus::Graded);
        $ungraded = $this->homework($plan, HomeworkStatus::Pending);
        $ungraded->delete();

        // Milestones domain now has zero non-deleted records (excluded);
        // homework domain has one non-deleted, graded record → 100%.
        $this->assertSame(100, $this->calculate($plan->refresh()));
        unset($graded);
    }

    public function test_records_belonging_to_another_plan_do_not_contribute(): void
    {
        $plan = $this->assignedPlan();
        $otherPlan = $this->assignedPlan($this->newGoal('Unrelated goal'));

        $this->milestone($plan, LearningPlanMilestoneStatus::Pending);
        $this->milestone($otherPlan, LearningPlanMilestoneStatus::Completed);
        $this->milestone($otherPlan, LearningPlanMilestoneStatus::Completed);

        $this->assertSame(0, $this->calculate($plan));
        $this->assertSame(100, $this->calculate($otherPlan));
    }

    public function test_public_and_free_text_review_content_does_not_contribute(): void
    {
        $plan = $this->assignedPlan();
        $this->milestone($plan, LearningPlanMilestoneStatus::Pending);

        app(LearningPlanService::class)->createReview($this->instructor, $plan->refresh(), [
            'summary' => 'Excellent! 100% mastery, fully complete, five stars.',
            'progress_notes' => 'progress: 100',
        ]);

        // Free-text review content — however phrased — must never be
        // parsed as a progress signal. Only the pending milestone counts.
        $this->assertSame(0, $this->calculate($plan->refresh()));
    }

    public function test_repeated_calculation_produces_the_same_result_without_mutation(): void
    {
        $plan = $this->assignedPlan();
        $this->milestone($plan, LearningPlanMilestoneStatus::Completed);
        $this->milestone($plan, LearningPlanMilestoneStatus::Pending);

        $calculator = app(LearningPlanProgressCalculator::class);
        $first = $calculator->calculate($plan);
        $second = $calculator->calculate($plan->refresh());

        $this->assertSame($first, $second);
        $this->assertSame(0, $plan->milestones()->onlyTrashed()->count());
    }

    // ── Milestone scenarios ───────────────────────────────────────────

    public function test_milestone_completion_updates_stored_progress(): void
    {
        $plan = $this->assignedPlan();
        $milestone = app(LearningPlanService::class)->createMilestone($this->instructor, $plan, ['title' => 'Solve linear equations']);

        app(LearningPlanService::class)->completeMilestone($this->instructor, $milestone);

        $this->assertSame(100, $plan->refresh()->progress_percent);
    }

    public function test_a_new_milestone_dilutes_progress_until_it_too_is_completed(): void
    {
        $plan = $this->assignedPlan();
        $service = app(LearningPlanService::class);
        $first = $service->createMilestone($this->instructor, $plan, ['title' => 'First']);
        $service->completeMilestone($this->instructor, $first);
        $this->assertSame(100, $plan->refresh()->progress_percent);

        // Adding a second, not-yet-complete milestone must immediately
        // recompute the denominator — continuous tracking, not only on
        // the next completion.
        $service->createMilestone($this->instructor, $plan, ['title' => 'Second']);
        $this->assertSame(50, $plan->refresh()->progress_percent);
    }

    public function test_calculator_reflects_a_milestone_moved_out_of_completed_status(): void
    {
        $plan = $this->assignedPlan();
        $milestone = $this->milestone($plan, LearningPlanMilestoneStatus::Completed);
        $this->assertSame(100, $this->calculate($plan));

        $milestone->forceFill(['status' => LearningPlanMilestoneStatus::Pending])->save();

        $this->assertSame(0, $this->calculate($plan->refresh()));
    }

    // ── Homework scenarios ────────────────────────────────────────────

    public function test_eligible_homework_completion_updates_stored_progress(): void
    {
        $plan = $this->assignedPlan();
        $assignment = app(HomeworkService::class)->assign(
            $this->instructor,
            $this->student,
            ['title' => 'Practice set', 'subject' => 'maths', 'due_at' => now()->addWeek()],
            learningPlanId: $plan->id,
        );
        $this->assertSame(0, $plan->refresh()->progress_percent);

        app(HomeworkService::class)->submit($assignment, 'Completed answers.');
        app(HomeworkService::class)->review($assignment, 'Great work.', 'A');

        $this->assertSame(100, $plan->refresh()->progress_percent);
    }

    public function test_unrelated_booking_only_homework_does_not_contribute(): void
    {
        $plan = $this->assignedPlan();
        $this->milestone($plan, LearningPlanMilestoneStatus::Completed);

        // Homework linked only to a booking (no learning_plan_id) must
        // never be counted toward this (or any) plan's progress.
        HomeworkAssignment::factory()->create([
            'teacher_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'status' => HomeworkStatus::Pending,
        ]);

        $this->assertSame(100, $this->calculate($plan));
    }

    public function test_calculator_reflects_homework_moved_out_of_graded_status(): void
    {
        $plan = $this->assignedPlan();
        $homework = $this->homework($plan, HomeworkStatus::Graded);
        $this->assertSame(100, $this->calculate($plan));

        $homework->forceFill(['status' => HomeworkStatus::Submitted])->save();

        $this->assertSame(0, $this->calculate($plan->refresh()));
    }

    // ── Persistence and concurrency scenarios ────────────────────────

    public function test_stored_progress_changes_only_when_the_computed_value_differs(): void
    {
        $plan = $this->assignedPlan();
        $milestone = app(LearningPlanService::class)->createMilestone($this->instructor, $plan, ['title' => 'Only milestone']);
        app(LearningPlanService::class)->completeMilestone($this->instructor, $milestone);

        $touchedAt = $plan->refresh()->updated_at;

        // Recalculating again with no evidence change must be a no-op —
        // no write, so updated_at must not move.
        app(LearningPlanProgressService::class)->recalculate($plan->refresh(), $this->instructor);

        $this->assertTrue($plan->refresh()->updated_at->equalTo($touchedAt));
    }

    public function test_repeated_milestone_completion_is_idempotent(): void
    {
        $plan = $this->assignedPlan();
        $milestone = app(LearningPlanService::class)->createMilestone($this->instructor, $plan, ['title' => 'Only milestone']);

        app(LearningPlanService::class)->completeMilestone($this->instructor, $milestone);
        app(LearningPlanService::class)->completeMilestone($this->instructor, $milestone->refresh());

        $this->assertSame(100, $plan->refresh()->progress_percent);
    }

    public function test_one_plans_recalculation_never_updates_another_plan(): void
    {
        $planA = $this->assignedPlan();
        $planB = $this->assignedPlan($this->newGoal('Second goal'));
        $this->milestone($planB, LearningPlanMilestoneStatus::Pending);

        $milestone = app(LearningPlanService::class)->createMilestone($this->instructor, $planA, ['title' => 'Plan A milestone']);
        app(LearningPlanService::class)->completeMilestone($this->instructor, $milestone);

        $this->assertSame(100, $planA->refresh()->progress_percent);
        $this->assertSame(0, $planB->refresh()->progress_percent);
    }

    public function test_recalculation_tolerates_a_missing_or_deleted_plan_safely(): void
    {
        app(LearningPlanProgressService::class)->recalculate(null, $this->instructor);

        $plan = $this->assignedPlan();
        $plan->delete();

        // No exception, no resurrection of the soft-deleted plan.
        app(LearningPlanProgressService::class)->recalculate($plan, $this->instructor);
        $this->assertTrue($plan->fresh()->trashed());
    }

    public function test_completed_plans_do_not_accept_new_progress_updates(): void
    {
        $plan = $this->assignedPlan();
        $milestone = app(LearningPlanService::class)->createMilestone($this->instructor, $plan, ['title' => 'Only milestone']);
        app(LearningPlanService::class)->completeMilestone($this->instructor, $milestone);

        $completed = app(LearningPlanService::class)->completePlan($this->instructor, $plan->refresh());
        $this->assertSame(100, $completed->progress_percent);

        // A second, would-be-incomplete milestone appearing after
        // completion (e.g. data correction) must never drag a frozen,
        // Completed plan's percentage back down.
        $completed->milestones()->create([
            'title' => 'Late addition',
            'status' => LearningPlanMilestoneStatus::Pending,
            'created_by' => $this->instructor->id,
            'updated_by' => $this->instructor->id,
        ]);
        app(LearningPlanProgressService::class)->recalculate($completed->refresh(), $this->instructor);

        $this->assertSame(100, $completed->fresh()->progress_percent);
        $this->assertSame(LearningPlanStatus::Completed, $completed->fresh()->status);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function calculate(StudentLearningPlan $plan): int
    {
        return app(LearningPlanProgressCalculator::class)->calculate($plan);
    }

    private function assignedPlan(?StudentLearningGoal $goal = null): StudentLearningPlan
    {
        $service = app(LearningPlanService::class);
        $plan = $service->createDraftFromGoal($this->student, $goal ?? $this->goal);

        Permission::firstOrCreate(['name' => 'Update:StudentLearningPlan', 'guard_name' => 'web']);
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');
        $manager->givePermissionTo('Update:StudentLearningPlan');

        return $service->assignInstructor($manager, $plan, $this->instructor);
    }

    private function newGoal(string $title): StudentLearningGoal
    {
        return StudentLearningGoal::create([
            'user_id' => $this->student->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'title' => $title,
            'type' => 'academic',
            'status' => 'active',
        ]);
    }

    private function milestone(StudentLearningPlan $plan, LearningPlanMilestoneStatus $status): LearningPlanMilestone
    {
        return $plan->milestones()->create([
            'title' => 'Milestone '.$status->value.' '.uniqid(),
            'status' => $status,
            'completed_at' => $status === LearningPlanMilestoneStatus::Completed ? now() : null,
            'created_by' => $this->instructor->id,
            'updated_by' => $this->instructor->id,
        ]);
    }

    private function homework(StudentLearningPlan $plan, HomeworkStatus $status): HomeworkAssignment
    {
        return HomeworkAssignment::factory()->forLearningPlan($plan)->create([
            'status' => $status,
            'submission_text' => $status !== HomeworkStatus::Pending ? 'Submitted answer.' : null,
            'submitted_at' => $status !== HomeworkStatus::Pending ? now() : null,
            'grade' => $status === HomeworkStatus::Graded ? 'A' : null,
        ]);
    }

    private function makeInstructor(): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $instructor->profile->update([
            'profile_visibility' => 'public',
            'instructor_status' => InstructorStatus::Approved,
        ]);

        return $instructor;
    }
}
