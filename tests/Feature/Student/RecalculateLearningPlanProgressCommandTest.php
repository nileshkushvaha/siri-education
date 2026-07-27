<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Enums\LearningPlanMilestoneStatus;
use App\Enums\LearningPlanStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\StudentLearningGoal;
use App\Models\StudentLearningPlan;
use App\Models\Subject;
use App\Models\User;
use App\Services\Student\LearningPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Historical backfill: `learning-plans:recalculate-progress`
 * must be safe to run against real historical data — dry-run never
 * writes, a single plan can be targeted, repeated runs are
 * idempotent, and a Completed/Archived plan's lifecycle and stored
 * value are never touched.
 */
class RecalculateLearningPlanProgressCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $instructor;

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

        $this->instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->instructor->assignRole('instructor');
        $this->instructor->profile->update(['profile_visibility' => 'public', 'instructor_status' => 'approved']);

        $category = AcademicCategory::create(['name' => 'Mathematics', 'slug' => 'mathematics']);
        $subject = Subject::create(['academic_category_id' => $category->id, 'name' => 'Algebra', 'slug' => 'algebra']);
        $level = AcademicLevel::create(['name' => 'High School', 'slug' => 'high-school']);

        $this->goal = StudentLearningGoal::create([
            'user_id' => $this->student->id,
            'subject_id' => $subject->id,
            'academic_level_id' => $level->id,
            'title' => 'Master algebra',
            'type' => 'academic',
            'status' => 'active',
            'created_by' => $this->student->id,
            'updated_by' => $this->student->id,
        ]);
    }

    /** A plan with a stale, pre-fix percentage: 1 milestone completed, 1 added afterward without ever recomputing. */
    private function planWithStalePercentage(): StudentLearningPlan
    {
        $service = app(LearningPlanService::class);
        $plan = $service->createDraftFromGoal($this->student, $this->goal);

        Permission::firstOrCreate(['name' => 'Update:StudentLearningPlan', 'guard_name' => 'web']);
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');
        $manager->givePermissionTo('Update:StudentLearningPlan');
        $plan = $service->assignInstructor($manager, $plan, $this->instructor);

        $plan->milestones()->create([
            'title' => 'Done',
            'status' => LearningPlanMilestoneStatus::Completed,
            'completed_at' => now(),
            'created_by' => $this->instructor->id,
            'updated_by' => $this->instructor->id,
        ]);
        // Simulates historical data: correct at the time (100%), stale
        // now that a second milestone exists and nothing recomputed it.
        $plan->forceFill(['progress_percent' => 100])->save();
        $plan->milestones()->create([
            'title' => 'Not done',
            'status' => LearningPlanMilestoneStatus::Pending,
            'created_by' => $this->instructor->id,
            'updated_by' => $this->instructor->id,
        ]);

        return $plan->refresh();
    }

    public function test_dry_run_reports_the_change_but_writes_nothing(): void
    {
        $plan = $this->planWithStalePercentage();
        $this->assertSame(100, $plan->progress_percent);

        $exitCode = Artisan::call('learning-plans:recalculate-progress', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Changed', Artisan::output());
        $this->assertSame(100, $plan->fresh()->progress_percent);
    }

    public function test_single_plan_targeting_only_touches_the_named_plan(): void
    {
        $stale = $this->planWithStalePercentage();

        $otherGoal = StudentLearningGoal::create([
            'user_id' => $this->student->id,
            'subject_id' => $stale->subject_id,
            'academic_level_id' => $stale->academic_level_id,
            'title' => 'Second goal',
            'type' => 'academic',
            'status' => 'active',
        ]);
        $other = app(LearningPlanService::class)->createDraftFromGoal($this->student, $otherGoal);
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');
        $manager->givePermissionTo('Update:StudentLearningPlan');
        $other = app(LearningPlanService::class)->assignInstructor($manager, $other, $this->instructor);
        $other->milestones()->create([
            'title' => 'Done', 'status' => LearningPlanMilestoneStatus::Completed, 'completed_at' => now(),
            'created_by' => $this->instructor->id, 'updated_by' => $this->instructor->id,
        ]);
        $other->forceFill(['progress_percent' => 100])->save();
        $other->milestones()->create([
            'title' => 'Not done', 'status' => LearningPlanMilestoneStatus::Pending,
            'created_by' => $this->instructor->id, 'updated_by' => $this->instructor->id,
        ]);
        $other->refresh();

        Artisan::call('learning-plans:recalculate-progress', ['--plan' => $stale->id]);

        $this->assertSame(50, $stale->fresh()->progress_percent);
        // Untargeted plan, equally stale, must be left untouched.
        $this->assertSame(100, $other->fresh()->progress_percent);
    }

    public function test_chunked_run_corrects_only_the_plans_whose_value_is_actually_wrong(): void
    {
        $stale = $this->planWithStalePercentage();
        $correct = app(LearningPlanService::class)->createDraftFromGoal(
            $this->student,
            StudentLearningGoal::create([
                'user_id' => $this->student->id,
                'subject_id' => $stale->subject_id,
                'academic_level_id' => $stale->academic_level_id,
                'title' => 'Already correct',
                'type' => 'academic',
                'status' => 'active',
            ]),
        );

        Artisan::call('learning-plans:recalculate-progress', ['--chunk' => 1]);
        $output = Artisan::output();

        $this->assertSame(50, $stale->fresh()->progress_percent);
        $this->assertSame(0, $correct->fresh()->progress_percent);
        $this->assertStringContainsString('Changed', $output);
        $this->assertStringContainsString('Unchanged', $output);
    }

    public function test_repeated_execution_is_idempotent(): void
    {
        $plan = $this->planWithStalePercentage();

        Artisan::call('learning-plans:recalculate-progress');
        $this->assertSame(50, $plan->fresh()->progress_percent);

        Artisan::call('learning-plans:recalculate-progress');
        $this->assertSame(50, $plan->fresh()->progress_percent);
    }

    public function test_lifecycle_states_remain_unchanged(): void
    {
        $plan = $this->planWithStalePercentage();
        $completed = app(LearningPlanService::class)->completePlan($this->instructor, $plan);
        $this->assertSame(LearningPlanStatus::Completed, $completed->status);
        $this->assertSame(100, $completed->progress_percent);

        Artisan::call('learning-plans:recalculate-progress');

        $fresh = $completed->fresh();
        $this->assertSame(LearningPlanStatus::Completed, $fresh->status);
        // Completed plans must never accept a new progress update, even
        // though the raw milestone math would say 50%.
        $this->assertSame(100, $fresh->progress_percent);
    }
}
