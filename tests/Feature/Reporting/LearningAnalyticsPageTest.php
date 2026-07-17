<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Enums\LearningPlanStatus;
use App\Filament\Pages\LearningAnalytics;
use App\Filament\Pages\ReportingHub;
use App\Homework\Enums\HomeworkStatus;
use App\Models\AcademicCategory;
use App\Models\HomeworkAssignment;
use App\Models\StudentLearningGoal;
use App\Models\StudentLearningPlan;
use App\Models\Subject;
use App\Models\User;
use App\Reporting\Contracts\ReportRegistryInterface;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 18F — Learning Analytics page: permission gate, section
 * rendering, honest unavailable-state messaging, privacy in rendered
 * output, Livewire hydration safety and Reporting Hub listing.
 */
class LearningAnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
    }

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
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');

        return $instructor;
    }

    // ── Access ───────────────────────────────────────────────────────────

    public function test_manager_can_open_the_page_with_freshness_banner(): void
    {
        $this->actingAs($this->manager())->get(LearningAnalytics::getUrl())
            ->assertOk()
            ->assertSee('Reporting timezone')
            ->assertSee('Live query');
    }

    public function test_user_without_learning_permission_is_denied(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)->get(LearningAnalytics::getUrl())->assertForbidden();
    }

    public function test_manager_without_learning_permission_is_denied_on_direct_route(): void
    {
        $admin = $this->manager();
        Role::findByName('manager', 'web')->revokePermissionTo('ViewLearningReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin)->get(LearningAnalytics::getUrl())->assertForbidden();
    }

    // ── Honest unavailable states ─────────────────────────────────────────

    public function test_unavailable_metrics_are_stated_never_fabricated(): void
    {
        $response = $this->actingAs($this->manager())->get(LearningAnalytics::getUrl());

        $response->assertOk()
            ->assertSee('Curriculum progress is unavailable')
            ->assertSee('Resource-usage analytics are unavailable')
            ->assertSee('No learning-consistency or academic score');
    }

    public function test_undefined_rates_render_na_never_zero_percent(): void
    {
        // One assignment with a future due date → no elapsed denominator.
        HomeworkAssignment::query()->create([
            'teacher_id' => $this->instructor()->id,
            'student_id' => $this->student()->id,
            'subject' => 'Maths',
            'title' => 'HW',
            'due_at' => now()->addDays(3),
            'status' => HomeworkStatus::Pending,
        ]);

        $this->actingAs($this->manager())->get(LearningAnalytics::getUrl())
            ->assertOk()
            ->assertSee('N/A (no elapsed due dates)')
            ->assertSee('N/A (no active plans)');
    }

    // ── Privacy in rendered output ────────────────────────────────────────

    public function test_private_academic_content_never_renders(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();

        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);
        $subject = Subject::query()->firstOrCreate(
            ['slug' => 'maths'],
            ['academic_category_id' => $category->id, 'name' => 'Maths', 'status' => 'active'],
        );

        $goal = StudentLearningGoal::query()->create([
            'user_id' => $student->id,
            'subject_id' => $subject->id,
            'title' => 'Goal',
            'type' => 'academic',
            'status' => 'active',
        ]);

        $plan = StudentLearningPlan::query()->create([
            'student_user_id' => $student->id,
            'learning_goal_id' => $goal->id,
            'primary_instructor_user_id' => $instructor->id,
            'subject_id' => $subject->id,
            'title' => 'Plan',
            'status' => LearningPlanStatus::Active,
            'progress_percent' => 10,
            'started_at' => now()->subDay(),
            'initial_assessment' => 'PRIVATE-ASSESSMENT-TEXT',
            'current_level_note' => 'PRIVATE-LEVEL-NOTE',
        ]);

        $plan->reviews()->create([
            'student_user_id' => $student->id,
            'instructor_user_id' => $instructor->id,
            'review_number' => 1,
            'summary' => 'PRIVATE-REVIEW-SUMMARY',
            'progress_notes' => 'PRIVATE-REVIEW-NOTES',
            'reviewed_at' => now()->subHour(),
        ]);

        HomeworkAssignment::query()->create([
            'teacher_id' => $instructor->id,
            'student_id' => $student->id,
            'subject' => 'Maths',
            'title' => 'HW',
            'due_at' => now()->subDay(),
            'status' => HomeworkStatus::Submitted,
            'submitted_at' => now()->subHours(2),
            'submission_text' => 'PRIVATE-SUBMISSION-BODY',
            'feedback' => 'PRIVATE-INSTRUCTOR-FEEDBACK',
            'grade' => 'PRIVATE-GRADE-VALUE',
        ]);

        $response = $this->actingAs($this->manager())->get(LearningAnalytics::getUrl());

        $response->assertOk();
        foreach ([
            'PRIVATE-ASSESSMENT-TEXT', 'PRIVATE-LEVEL-NOTE', 'PRIVATE-REVIEW-SUMMARY',
            'PRIVATE-REVIEW-NOTES', 'PRIVATE-SUBMISSION-BODY', 'PRIVATE-INSTRUCTOR-FEEDBACK',
            'PRIVATE-GRADE-VALUE',
        ] as $secret) {
            $response->assertDontSee($secret);
        }
    }

    public function test_no_financial_field_renders_on_the_learning_page(): void
    {
        // Remove finance permissions so no finance nav item renders — any
        // remaining financial term would have to come from the report itself.
        $admin = $this->manager();
        foreach (['ViewFinanceReports', 'ViewWalletReports', 'ViewPaymentReports', 'ViewInstructorCompensationReports'] as $permission) {
            Role::findByName('manager', 'web')->revokePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin)->get(LearningAnalytics::getUrl())
            ->assertOk()
            ->assertDontSee('Wallet')
            ->assertDontSee('Revenue')
            ->assertDontSee('₹');
    }

    // ── Livewire hydration safety (Phase 18C regression class) ────────────

    public function test_string_filter_hydration_never_throws(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(LearningAnalytics::class)
            ->set('planStatus', 'active')
            ->set('goalStatus', 'completed')
            ->set('homeworkStatus', 'pending')
            ->set('subjectId', 'not-a-real-uuid')
            ->set('periodPreset', 'last_7_days')
            ->assertOk();
    }

    public function test_reset_filters_restores_defaults(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(LearningAnalytics::class)
            ->set('planStatus', 'paused')
            ->set('periodPreset', 'last_7_days')
            ->call('resetFilters')
            ->assertSet('planStatus', null)
            ->assertSet('periodPreset', 'last_30_days');
    }

    // ── Registry & hub integration ────────────────────────────────────────

    public function test_learning_reports_are_available_in_the_registry_with_real_routes(): void
    {
        $registry = app(ReportRegistryInterface::class);

        foreach (['learning_progress', 'learning_plan_report', 'homework_report'] as $key) {
            $definition = $registry->find($key);

            $this->assertNotNull($definition, $key);
            $this->assertTrue($definition->available, "{$key} must be available.");
            $this->assertSame('ViewLearningReports', $definition->requiredViewPermission);
            $this->assertSame(LearningAnalytics::class, $definition->routeName);
        }
    }

    public function test_reporting_hub_lists_learning_analytics_for_authorized_manager(): void
    {
        $this->actingAs($this->manager())->get(ReportingHub::getUrl())
            ->assertOk()
            ->assertSee('Learning Analytics');
    }
}
