<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Enums\InstructorStatus;
use App\Enums\LearningPlanStatus;
use App\Filament\Resources\StudentLearningPlans\Pages\EditStudentLearningPlan;
use App\Filament\Resources\StudentLearningPlans\Pages\ListStudentLearningPlans;
use App\Filament\Resources\StudentLearningPlans\StudentLearningPlanResource;
use App\Livewire\Frontend\Instructor\DashboardOverview;
use App\Livewire\Frontend\Instructor\LearningPlans as InstructorLearningPlans;
use App\Livewire\Frontend\Student\LearningPlans as StudentLearningPlans;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\StudentLearningGoal;
use App\Models\StudentLearningPlan;
use App\Models\Subject;
use App\Models\User;
use App\Services\Student\LearningPlanService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LearningPlanHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $otherStudent;

    private User $instructor;

    private User $otherInstructor;

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

        $this->otherStudent = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->otherStudent->assignRole('student');

        $this->instructor = $this->makeInstructor(InstructorStatus::Approved);
        $this->otherInstructor = $this->makeInstructor(InstructorStatus::Approved);

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

    public function test_admin_without_learning_plan_permission_cannot_access_plan_resource(): void
    {
        $admin = $this->manager();

        $this->actingAs($admin)
            ->get(StudentLearningPlanResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_permitted_admin_can_view_plan_resource_and_create_route_is_removed(): void
    {
        $admin = $this->manager(['ViewAny:StudentLearningPlan']);

        $this->actingAs($admin);

        Livewire::test(ListStudentLearningPlans::class)
            ->assertSuccessful();

        $this->get('/admin/student-learning-plans/create')->assertNotFound();
    }

    public function test_non_permitted_admin_cannot_use_lifecycle_actions(): void
    {
        $plan = $this->assignedPlan();
        $admin = $this->manager(['ViewAny:StudentLearningPlan', 'View:StudentLearningPlan']);

        $this->assertFalse($admin->can('update', $plan));

        $this->expectException(AuthorizationException::class);
        app(LearningPlanService::class)->assignInstructor($admin, $plan, $this->otherInstructor);
    }

    public function test_permitted_admin_uses_service_backed_lifecycle_actions(): void
    {
        $plan = app(LearningPlanService::class)->createDraftFromGoal($this->student, $this->goal);
        $admin = $this->manager([
            'ViewAny:StudentLearningPlan',
            'View:StudentLearningPlan',
            'Update:StudentLearningPlan',
        ]);

        $this->actingAs($admin);

        Livewire::test(EditStudentLearningPlan::class, ['record' => $plan->getRouteKey()])
            ->callAction('assignInstructor', data: ['primary_instructor_user_id' => $this->instructor->id])
            ->assertHasNoActionErrors()
            ->callAction('activatePlan')
            ->assertHasNoActionErrors()
            ->callAction('markReviewDue')
            ->assertHasNoActionErrors()
            ->callAction('adjustPlan', data: [
                'title' => 'Updated algebra plan',
                'current_focus' => 'Quadratics',
                'reason' => 'Assessment showed a new priority.',
            ])
            ->assertHasNoActionErrors()
            ->callAction('completePlan')
            ->assertHasNoActionErrors()
            ->callAction('archivePlan')
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('learning_plan_adjustments', [
            'learning_plan_id' => $plan->id,
            'change_reason' => 'Assessment showed a new priority.',
        ]);
        $this->assertSame(LearningPlanStatus::Archived, $plan->fresh()->status);
        $this->assertDatabaseHas('activity_log', ['event' => 'learning_plan_instructor_assigned']);
        $this->assertDatabaseHas('activity_log', ['event' => 'learning_plan_review_due']);
        $this->assertDatabaseHas('activity_log', ['event' => 'learning_plan_completed']);
        $this->assertDatabaseHas('activity_log', ['event' => 'learning_plan_archived']);
    }

    public function test_admin_adjustment_action_requires_reason(): void
    {
        $plan = $this->assignedPlan();
        $admin = $this->manager([
            'ViewAny:StudentLearningPlan',
            'View:StudentLearningPlan',
            'Update:StudentLearningPlan',
        ]);

        $this->actingAs($admin);

        Livewire::test(EditStudentLearningPlan::class, ['record' => $plan->getRouteKey()])
            ->callAction('adjustPlan', data: ['current_focus' => 'Quadratics', 'reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);
    }

    public function test_student_learning_plan_page_is_owner_scoped_and_can_start_draft_from_goal(): void
    {
        $otherPlan = $this->planForOtherStudent();

        $this->actingAs($this->student)
            ->get(route('dashboard.learning-plans'))
            ->assertOk()
            ->assertDontSee($otherPlan->title);

        Livewire::actingAs($this->student)
            ->test(StudentLearningPlans::class)
            ->call('createFromGoal', $this->goal->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('student_learning_plans', [
            'student_user_id' => $this->student->id,
            'learning_goal_id' => $this->goal->id,
        ]);
    }

    public function test_instructor_learning_plan_page_is_assigned_scope_only_and_workbench_actions_use_service(): void
    {
        $plan = $this->assignedPlan();

        Livewire::actingAs($this->otherInstructor)
            ->test(InstructorLearningPlans::class)
            ->assertDontSee($plan->title);

        Livewire::actingAs($this->instructor)
            ->test(InstructorLearningPlans::class)
            ->assertSee($plan->title)
            ->set('recommendedFocus', 'Linear equations')
            ->set('assessmentNotes', 'Needs confidence with word problems.')
            ->call('recordAssessment')
            ->set('milestoneTitle', 'Solve linear equations')
            ->call('createMilestone')
            ->set('reviewSummary', 'Good progress.')
            ->call('createReview')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('learning_plan_assessments', ['learning_plan_id' => $plan->id]);
        $this->assertDatabaseHas('learning_plan_milestones', ['learning_plan_id' => $plan->id]);
        $this->assertDatabaseHas('learning_plan_reviews', ['learning_plan_id' => $plan->id]);
    }

    public function test_instructor_dashboard_shows_read_only_learning_plan_counters(): void
    {
        $active = $this->assignedPlan();
        app(LearningPlanService::class)->activate($this->instructor, $active);

        $reviewDueGoal = $this->newGoal('Review due goal');
        $reviewDue = app(LearningPlanService::class)->createDraftFromGoal($this->student, $reviewDueGoal);
        $reviewDue = $this->assignWithManager($reviewDue, $this->instructor);
        app(LearningPlanService::class)->markReviewDue($this->instructor, $reviewDue);

        $pendingGoal = $this->newGoal('Pending assessment goal');
        $pending = app(LearningPlanService::class)->createDraftFromGoal($this->student, $pendingGoal);
        $this->assignWithManager($pending, $this->instructor);

        Livewire::actingAs($this->instructor)
            ->test(DashboardOverview::class)
            ->assertSet('assignedActiveLearningPlanCount', 2)
            ->assertSet('reviewDueLearningPlanCount', 1)
            ->assertSet('pendingAssessmentLearningPlanCount', 1);
    }

    private function assignedPlan(): StudentLearningPlan
    {
        $plan = app(LearningPlanService::class)->createDraftFromGoal($this->student, $this->goal);

        return $this->assignWithManager($plan, $this->instructor);
    }

    private function assignWithManager(StudentLearningPlan $plan, User $instructor): StudentLearningPlan
    {
        return app(LearningPlanService::class)->assignInstructor(
            $this->manager(['Update:StudentLearningPlan']),
            $plan,
            $instructor,
        );
    }

    private function planForOtherStudent(): StudentLearningPlan
    {
        $goal = StudentLearningGoal::create([
            'user_id' => $this->otherStudent->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'title' => 'Private other student goal',
            'type' => 'academic',
            'status' => 'active',
        ]);

        return app(LearningPlanService::class)->createDraftFromGoal($this->otherStudent, $goal);
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

    /**
     * @param  array<int, string>  $permissions
     */
    private function manager(array $permissions = []): User
    {
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        if ($permissions !== []) {
            $manager->givePermissionTo($permissions);
        }

        return $manager;
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
