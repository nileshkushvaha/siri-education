<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Enums\InstructorStatus;
use App\Enums\LearningPlanMilestoneStatus;
use App\Enums\LearningPlanStatus;
use App\Enums\StudentStatus;
use App\Homework\Enums\HomeworkStatus;
use App\Lessons\Enums\LessonOutcome;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\HomeworkAssignment;
use App\Models\Lesson;
use App\Models\StudentLearningGoal;
use App\Models\StudentLearningPlan;
use App\Models\Subject;
use App\Models\User;
use App\Services\Student\LearningPlanProgressCalculator;
use App\Services\Student\LearningPlanProgressService;
use App\Services\Student\LearningPlanService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS §6.17.5 / §6.17.10 (GAP-023 — final blocker): a learning-plan
 * review may carry a structured, instructor-entered overall progress
 * percentage. Only the latest eligible finalized review (reviewed_at
 * set, progress_percent non-null) supplies the review-domain
 * percentage — never an average, never free-text inference.
 */
class LearningPlanReviewProgressTest extends TestCase
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

    // ── Schema and validation ─────────────────────────────────────────

    public function test_progress_percent_is_nullable(): void
    {
        $plan = $this->assignedPlan();

        $review = app(LearningPlanService::class)->createReview($this->instructor, $plan, ['summary' => 'Doing fine.']);

        $this->assertNull($review->progress_percent);
    }

    public function test_zero_is_accepted(): void
    {
        $plan = $this->assignedPlan();

        $review = app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => 0]);

        $this->assertSame(0, $review->progress_percent);
    }

    public function test_one_hundred_is_accepted(): void
    {
        $plan = $this->assignedPlan();

        $review = app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => 100]);

        $this->assertSame(100, $review->progress_percent);
    }

    public function test_negative_values_are_rejected(): void
    {
        $plan = $this->assignedPlan();

        $this->expectException(ValidationException::class);
        app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => -1]);
    }

    public function test_values_above_100_are_rejected(): void
    {
        $plan = $this->assignedPlan();

        $this->expectException(ValidationException::class);
        app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => 101]);
    }

    public function test_decimal_values_are_rejected(): void
    {
        $plan = $this->assignedPlan();

        $this->expectException(ValidationException::class);
        app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => 55.5]);
    }

    public function test_malformed_string_values_are_rejected(): void
    {
        $plan = $this->assignedPlan();

        $this->expectException(ValidationException::class);
        app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => 'abc']);
    }

    public function test_array_values_are_rejected(): void
    {
        $plan = $this->assignedPlan();

        $this->expectException(ValidationException::class);
        app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => [50]]);
    }

    public function test_database_constraint_rejects_out_of_range_persisted_values(): void
    {
        $plan = $this->assignedPlan();

        $this->expectException(QueryException::class);

        DB::table('learning_plan_reviews')->insert([
            'learning_plan_id' => $plan->id,
            'student_user_id' => $plan->student_user_id,
            'review_number' => 1,
            'progress_percent' => 150,
            'reviewed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_legacy_review_without_a_percentage_remains_valid(): void
    {
        $plan = $this->assignedPlan();
        $review = $plan->reviews()->create([
            'student_user_id' => $plan->student_user_id,
            'review_number' => 1,
            'summary' => 'Pre-Phase-26D historical note.',
            'reviewed_at' => now()->subMonths(3),
            'created_by' => $this->instructor->id,
        ]);

        $this->assertNull($review->progress_percent);
        $this->assertNotNull($review->fresh());
    }

    // ── Authorization ─────────────────────────────────────────────────

    public function test_authorized_primary_instructor_can_submit_the_value(): void
    {
        $plan = $this->assignedPlan();

        $review = app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => 70]);

        $this->assertSame(70, $review->progress_percent);
    }

    public function test_unrelated_instructor_cannot_submit_it(): void
    {
        $plan = $this->assignedPlan();
        $unrelated = $this->makeInstructor();

        $this->expectException(AuthorizationException::class);
        app(LearningPlanService::class)->createReview($unrelated, $plan, ['progress_percent' => 70]);
    }

    public function test_student_cannot_manipulate_it(): void
    {
        $plan = $this->assignedPlan();

        $this->expectException(AuthorizationException::class);
        app(LearningPlanService::class)->createReview($this->student, $plan, ['progress_percent' => 70]);
    }

    public function test_crafted_plan_id_cannot_cross_instructor_ownership(): void
    {
        $plan = $this->assignedPlan();
        $otherInstructor = $this->makeInstructor();

        // The Livewire workbench scopes lookups to assignedLearningPlans() —
        // a crafted id for a plan this instructor does not own must never resolve.
        $this->assertFalse($otherInstructor->assignedLearningPlans()->whereKey($plan->id)->exists());
    }

    public function test_existing_administrator_authority_remains_unchanged(): void
    {
        $plan = $this->assignedPlan();
        Permission::firstOrCreate(['name' => 'Create:LearningPlanReview', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('manager');
        $admin->givePermissionTo('Create:LearningPlanReview');

        $review = app(LearningPlanService::class)->createReview($admin, $plan, ['progress_percent' => 85]);

        $this->assertSame(85, $review->progress_percent);
    }

    public function test_completed_or_archived_plan_rules_remain_enforced(): void
    {
        $plan = $this->assignedPlan();
        $milestone = app(LearningPlanService::class)->createMilestone($this->instructor, $plan, ['title' => 'Only']);
        app(LearningPlanService::class)->completeMilestone($this->instructor, $milestone);
        $completed = app(LearningPlanService::class)->completePlan($this->instructor, $plan->refresh());

        $this->expectException(ValidationException::class);
        app(LearningPlanService::class)->createReview($this->instructor, $completed, ['progress_percent' => 50]);
    }

    // ── Calculator scenarios ──────────────────────────────────────────

    public function test_percentage_less_review_domain_returns_null(): void
    {
        $plan = $this->assignedPlan();
        app(LearningPlanService::class)->createReview($this->instructor, $plan, ['summary' => 'No number given.']);

        $this->assertSame(0, $this->calculate($plan));
    }

    public function test_one_eligible_structured_review_contributes_its_exact_value(): void
    {
        $plan = $this->assignedPlan();
        app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => 42]);

        $this->assertSame(42, $this->calculate($plan));
    }

    public function test_latest_eligible_review_wins(): void
    {
        $plan = $this->assignedPlan();
        app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => 20, 'reviewed_at' => now()->subDays(10)]);
        app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => 80, 'reviewed_at' => now()]);

        $this->assertSame(80, $this->calculate($plan));
    }

    public function test_deterministic_tie_breaking_uses_primary_key_descending(): void
    {
        $plan = $this->assignedPlan();
        $sameInstant = now();
        app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => 30, 'reviewed_at' => $sameInstant]);
        $second = app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => 90, 'reviewed_at' => $sameInstant]);

        $this->assertSame($second->progress_percent, $this->calculate($plan));
    }

    public function test_older_review_remains_historical_but_is_not_averaged(): void
    {
        $plan = $this->assignedPlan();
        app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => 20, 'reviewed_at' => now()->subDays(20)]);
        app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => 50, 'reviewed_at' => now()->subDays(10)]);
        app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => 80, 'reviewed_at' => now()]);

        // Not (20+50+80)/3 = 50 — only the latest (80) counts.
        $this->assertSame(80, $this->calculate($plan));
        $this->assertSame(3, $plan->reviews()->count());
    }

    public function test_unfinalized_review_with_null_reviewed_at_does_not_contribute(): void
    {
        $plan = $this->assignedPlan();
        // Bypasses the service (which always sets reviewed_at) to model a
        // hypothetical unfinalized row — the eligibility rule must still
        // exclude it even if one existed.
        $plan->reviews()->create([
            'student_user_id' => $plan->student_user_id,
            'review_number' => 1,
            'progress_percent' => 90,
            'reviewed_at' => null,
            'created_by' => $this->instructor->id,
        ]);

        $this->assertSame(0, $this->calculate($plan));
    }

    public function test_free_text_containing_a_percentage_is_ignored(): void
    {
        $plan = $this->assignedPlan();
        app(LearningPlanService::class)->createReview($this->instructor, $plan, [
            'summary' => 'Student is at 100% mastery, five stars, fully complete.',
            'progress_notes' => 'progress: 100',
        ]);

        $this->assertSame(0, $this->calculate($plan));
    }

    public function test_another_plans_review_does_not_contribute(): void
    {
        $plan = $this->assignedPlan();
        $otherPlan = $this->assignedPlan($this->newGoal('Other goal'));
        app(LearningPlanService::class)->createReview($this->instructor, $otherPlan, ['progress_percent' => 99]);

        $this->assertSame(0, $this->calculate($plan));
        $this->assertSame(99, $this->calculate($otherPlan));
    }

    public function test_zero_is_an_applicable_domain_not_treated_as_null(): void
    {
        $plan = $this->assignedPlan();
        app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => 0]);
        $this->milestone($plan, LearningPlanMilestoneStatus::Completed);

        // Average of two applicable domains: (0 + 100) / 2 = 50 — proves
        // the review domain (0) was included, not excluded as "no value".
        $this->assertSame(50, $this->calculate($plan));
    }

    public function test_mixed_evidence_across_all_four_domains_uses_the_equal_weight_composite(): void
    {
        $plan = $this->assignedPlan();
        $this->milestone($plan, LearningPlanMilestoneStatus::Completed); // 100%
        HomeworkAssignment::factory()->forLearningPlan($plan)->create(['status' => HomeworkStatus::Pending]); // 0%
        Lesson::factory()->forLearningPlan($plan)->withOutcome(LessonOutcome::Completed)->create(); // 100%
        app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => 40]); // 40%

        // (100 + 0 + 100 + 40) / 4 = 60.
        $this->assertSame(60, $this->calculate($plan));
    }

    public function test_repeated_calculation_is_deterministic_and_non_mutating(): void
    {
        $plan = $this->assignedPlan();
        app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => 55]);

        $calculator = app(LearningPlanProgressCalculator::class);
        $first = $calculator->calculate($plan);
        $second = $calculator->calculate($plan->refresh());

        $this->assertSame($first, $second);
        $this->assertSame(1, $plan->reviews()->count());
    }

    // ── Recalculation and persistence ─────────────────────────────────

    public function test_creating_an_eligible_review_updates_stored_progress(): void
    {
        $plan = $this->assignedPlan();

        app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => 65]);

        $this->assertSame(65, $plan->fresh()->progress_percent);
    }

    public function test_percentage_less_review_does_not_unnecessarily_change_stored_progress(): void
    {
        $plan = $this->assignedPlan();
        $milestone = app(LearningPlanService::class)->createMilestone($this->instructor, $plan, ['title' => 'Only']);
        app(LearningPlanService::class)->completeMilestone($this->instructor, $milestone);
        $this->assertSame(100, $plan->fresh()->progress_percent);
        $touchedAt = $plan->fresh()->updated_at;

        app(LearningPlanService::class)->createReview($this->instructor, $plan, ['summary' => 'No number.']);

        $this->assertSame(100, $plan->fresh()->progress_percent);
        $this->assertTrue($plan->fresh()->updated_at->equalTo($touchedAt));
    }

    public function test_unchanged_recalculation_does_not_bump_updated_at(): void
    {
        $plan = $this->assignedPlan();
        app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => 55]);
        $touchedAt = $plan->fresh()->updated_at;

        app(LearningPlanProgressService::class)->recalculate($plan->fresh(), $this->instructor);

        $this->assertTrue($plan->fresh()->updated_at->equalTo($touchedAt));
    }

    public function test_repeated_recalculation_is_idempotent(): void
    {
        $plan = $this->assignedPlan();
        app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => 55]);

        app(LearningPlanProgressService::class)->recalculate($plan->fresh(), $this->instructor);
        app(LearningPlanProgressService::class)->recalculate($plan->fresh(), $this->instructor);

        $this->assertSame(55, $plan->fresh()->progress_percent);
    }

    public function test_only_the_affected_plan_changes(): void
    {
        $plan = $this->assignedPlan();
        $otherPlan = $this->assignedPlan($this->newGoal('Untouched goal'));
        $this->milestone($otherPlan, LearningPlanMilestoneStatus::Pending);

        app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => 60]);

        $this->assertSame(60, $plan->fresh()->progress_percent);
        $this->assertSame(0, $otherPlan->fresh()->progress_percent);
    }

    public function test_missing_or_deleted_plan_is_handled_safely(): void
    {
        app(LearningPlanProgressService::class)->recalculate(null, $this->instructor);

        $plan = $this->assignedPlan();
        $plan->delete();

        app(LearningPlanProgressService::class)->recalculate($plan, $this->instructor);
        $this->assertTrue($plan->fresh()->trashed());
    }

    public function test_completed_or_archived_plan_progress_remains_unchanged(): void
    {
        $plan = $this->assignedPlan();
        app(LearningPlanService::class)->createReview($this->instructor, $plan, ['progress_percent' => 30]);
        $completed = app(LearningPlanService::class)->completePlan($this->instructor, $plan->refresh());
        $this->assertSame(100, $completed->progress_percent);

        app(LearningPlanProgressService::class)->recalculate($completed->fresh(), $this->instructor);

        $this->assertSame(100, $completed->fresh()->progress_percent);
        $this->assertSame(LearningPlanStatus::Completed, $completed->fresh()->status);
    }

    public function test_bounded_query_behaviour_is_preserved(): void
    {
        $plan = $this->assignedPlan();
        for ($i = 0; $i < 5; $i++) {
            app(LearningPlanService::class)->createReview($this->instructor, $plan->refresh(), [
                'progress_percent' => $i * 10,
                'reviewed_at' => now()->subDays(5 - $i),
            ]);
        }
        $plan->refresh();

        DB::enableQueryLog();
        app(LearningPlanProgressCalculator::class)->calculate($plan);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 1 bounded query per domain (milestones total+completed = 2,
        // homework total+completed = 2, lessons total+completed = 2,
        // reviews latest-only = 1) regardless of how many reviews exist.
        $this->assertLessThanOrEqual(7, $count);
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

    private function milestone(StudentLearningPlan $plan, LearningPlanMilestoneStatus $status): void
    {
        $plan->milestones()->create([
            'title' => 'Milestone '.$status->value.' '.uniqid(),
            'status' => $status,
            'completed_at' => $status === LearningPlanMilestoneStatus::Completed ? now() : null,
            'created_by' => $this->instructor->id,
            'updated_by' => $this->instructor->id,
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
