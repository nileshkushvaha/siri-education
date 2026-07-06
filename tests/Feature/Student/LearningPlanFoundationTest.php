<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Enums\InstructorStatus;
use App\Enums\LearningPlanStatus;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\Booking;
use App\Models\HomeworkAssignment;
use App\Models\StudentLearningGoal;
use App\Models\StudentLearningPlan;
use App\Models\Subject;
use App\Models\User;
use App\Services\Student\LearningPlanService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LearningPlanFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $otherStudent;

    private User $instructor;

    private Subject $subject;

    private AcademicLevel $level;

    private StudentLearningGoal $goal;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');

        $this->otherStudent = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->otherStudent->assignRole('student');

        $this->instructor = $this->makeInstructor(InstructorStatus::Approved);

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

    public function test_student_can_create_draft_plan_from_own_goal_and_not_another_students_goal(): void
    {
        $plan = app(LearningPlanService::class)->createDraftFromGoal($this->student, $this->goal);

        $this->assertSame($this->student->id, $plan->student_user_id);
        $this->assertSame($this->goal->id, $plan->learning_goal_id);
        $this->assertSame($this->subject->id, $plan->subject_id);
        $this->assertSame(LearningPlanStatus::Draft, $plan->status);
        $this->assertDatabaseHas('activity_log', ['event' => 'learning_plan_created']);

        $otherGoal = StudentLearningGoal::create([
            'user_id' => $this->student->id,
            'subject_id' => $this->subject->id,
            'title' => 'Other owned goal',
            'type' => 'personal',
            'status' => 'active',
        ]);

        $this->expectException(AuthorizationException::class);
        app(LearningPlanService::class)->createDraftFromGoal($this->otherStudent, $otherGoal);
    }

    public function test_only_one_open_plan_per_learning_goal_is_allowed(): void
    {
        app(LearningPlanService::class)->createDraftFromGoal($this->student, $this->goal);

        $this->expectException(ValidationException::class);
        app(LearningPlanService::class)->createDraftFromGoal($this->student, $this->goal);
    }

    public function test_learning_plan_does_not_create_booking_payment_or_homework_records(): void
    {
        app(LearningPlanService::class)->createDraftFromGoal($this->student, $this->goal);

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, HomeworkAssignment::count());
        $this->assertFalse(Schema::hasTable('wallets'));
        $this->assertFalse(Schema::hasTable('payments'));
    }

    public function test_only_bookable_instructor_can_be_assigned(): void
    {
        $plan = app(LearningPlanService::class)->createDraftFromGoal($this->student, $this->goal);

        Permission::firstOrCreate(['name' => 'Update:StudentLearningPlan', 'guard_name' => 'web']);
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');
        $manager->givePermissionTo('Update:StudentLearningPlan');

        $assigned = app(LearningPlanService::class)->assignInstructor($manager, $plan, $this->instructor);

        $this->assertSame($this->instructor->id, $assigned->primary_instructor_user_id);
        $this->assertSame(LearningPlanStatus::AwaitingAssessment, $assigned->status);

        $badInstructor = $this->makeInstructor(InstructorStatus::Suspended);

        $this->expectException(ValidationException::class);
        app(LearningPlanService::class)->assignInstructor($manager, $assigned, $badInstructor);
    }

    public function test_assigned_instructor_can_record_assessment_milestone_and_review(): void
    {
        $plan = $this->assignedPlan();
        $service = app(LearningPlanService::class);

        $assessment = $service->recordAssessment($this->instructor, $plan, [
            'assessment_type' => 'initial',
            'current_level' => 'Grade 9 algebra readiness',
            'recommended_focus' => 'Linear equations',
            'notes' => 'Needs confidence with word problems.',
        ]);

        $this->assertSame($plan->id, $assessment->learning_plan_id);
        $this->assertTrue($this->student->can('view', $assessment));
        $this->assertFalse($this->otherStudent->can('view', $assessment));

        $milestone = $service->createMilestone($this->instructor, $plan, ['title' => 'Solve linear equations']);
        $this->assertTrue($this->student->can('view', $milestone));

        $completed = $service->completeMilestone($this->instructor, $milestone);
        $this->assertSame('completed', $completed->status->value);
        $this->assertSame(100, $plan->refresh()->progress_percent);

        $review = $service->createReview($this->instructor, $plan->refresh(), ['summary' => 'Good early progress.']);
        $this->assertSame(1, $review->review_number);
        $this->assertTrue($this->student->can('view', $review));
    }

    public function test_unrelated_instructor_and_student_cannot_manage_plan(): void
    {
        $plan = $this->assignedPlan();
        $unrelatedInstructor = $this->makeInstructor(InstructorStatus::Approved);

        $this->assertFalse($unrelatedInstructor->can('view', $plan));
        $this->assertFalse($this->otherStudent->can('view', $plan));

        $this->expectException(AuthorizationException::class);
        app(LearningPlanService::class)->createMilestone($unrelatedInstructor, $plan, ['title' => 'Unauthorized']);
    }

    public function test_student_cannot_create_milestone(): void
    {
        $plan = $this->assignedPlan();

        $this->expectException(AuthorizationException::class);
        app(LearningPlanService::class)->createMilestone($this->student, $plan, ['title' => 'Student-created milestone']);
    }

    public function test_adjustment_history_requires_reason_and_completed_plan_is_locked(): void
    {
        $plan = $this->assignedPlan();
        $service = app(LearningPlanService::class);

        $service->adjustPlan($this->instructor, $plan, ['current_focus' => 'Quadratics'], 'Assessment changed focus.');

        $this->assertDatabaseHas('learning_plan_adjustments', [
            'learning_plan_id' => $plan->id,
            'change_reason' => 'Assessment changed focus.',
        ]);
        $this->assertDatabaseHas('activity_log', ['event' => 'learning_plan_adjusted']);

        $completed = $service->completePlan($this->instructor, $plan->refresh());
        $this->assertSame(LearningPlanStatus::Completed, $completed->status);

        $this->expectException(ValidationException::class);
        $service->createReview($this->instructor, $completed, ['summary' => 'Too late']);
    }

    public function test_lifecycle_review_due_completed_and_archived_remain_historical(): void
    {
        $plan = $this->assignedPlan();
        $service = app(LearningPlanService::class);

        $active = $service->activate($this->instructor, $plan);
        $this->assertSame(LearningPlanStatus::Active, $active->status);

        $reviewDue = $service->markReviewDue($this->instructor, $active);
        $this->assertSame(LearningPlanStatus::ReviewDue, $reviewDue->status);
        $this->assertDatabaseHas('activity_log', ['event' => 'learning_plan_review_due']);

        $completed = $service->completePlan($this->instructor, $reviewDue);
        $this->assertSame(LearningPlanStatus::Completed, $completed->status);
        $this->assertTrue($this->student->studentLearningPlans()->historical()->whereKey($completed->id)->exists());

        $archived = $service->archivePlan($this->instructor, $completed);
        $this->assertSame(LearningPlanStatus::Archived, $archived->status);
    }

    public function test_routes_are_protected_and_no_duplicate_tables_exist(): void
    {
        $this->get(route('dashboard.learning-plans'))->assertRedirect(route('auth.login'));

        $this->actingAs($this->student)
            ->get(route('dashboard.learning-plans'))
            ->assertOk()
            ->assertSee('Learning Plans');

        $this->assertFalse(Schema::hasTable('students'));
        $this->assertFalse(Schema::hasTable('student_profiles'));
        $this->assertFalse(Schema::hasTable('instructors'));
        $this->assertFalse(Schema::hasTable('instructor_profiles'));
    }

    private function assignedPlan(): StudentLearningPlan
    {
        $service = app(LearningPlanService::class);
        $plan = $service->createDraftFromGoal($this->student, $this->goal);

        Permission::firstOrCreate(['name' => 'Update:StudentLearningPlan', 'guard_name' => 'web']);
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');
        $manager->givePermissionTo('Update:StudentLearningPlan');

        return $service->assignInstructor($manager, $plan, $this->instructor);
    }

    private function makeInstructor(InstructorStatus $status): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $instructor->profile->update([
            'profile_visibility' => 'public',
            'instructor_status' => $status,
        ]);

        return $instructor;
    }
}
