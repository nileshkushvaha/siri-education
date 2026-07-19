<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\DTOs\StudentDashboard\StudentDashboardData;
use App\Enums\StudentStatus;
use App\Homework\Contracts\HomeworkServiceInterface;
use App\Models\User;
use App\Services\Student\StudentDashboardService;
use App\Settings\FeatureSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class Phase20DStudentDashboardClosureTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);
        $this->student->assignRole('student');
        $this->student->profile()->update(['student_status' => StudentStatus::Active]); // Phase 24H.2: interactive student actions require Active status.
    }

    public function test_dashboard_markup_has_accessible_progress_and_responsive_contracts(): void
    {
        $dashboard = new StudentDashboardData(
            nextLesson: null,
            homework: null,
            learningJourney: [
                'title' => 'Math plan', 'subject' => 'Math', 'instructor' => null, 'goal' => null,
                'progress' => 45, 'completed_milestones' => 1, 'total_milestones' => 2,
                'next_milestone' => null, 'last_review_at' => null, 'last_review' => null,
            ],
            wallet: null,
            referral: null,
            favorites: [],
            notifications: null,
            profile: null,
        );

        $this->view('livewire.frontend.student.dashboard-overview', compact('dashboard'))
            ->assertSee('role="progressbar"', false)
            ->assertSee('aria-valuemin="0"', false)
            ->assertSee('aria-valuemax="100"', false)
            ->assertSee('min-h-11', false)
            ->assertSee('grid gap-4 md:grid-cols-2 xl:grid-cols-4', false);
    }

    public function test_disabled_optional_domains_execute_no_domain_queries(): void
    {
        $features = app(FeatureSettings::class);
        $features->homework_enabled = false;
        $features->wallet_enabled = false;
        $features->referral_enabled = false;
        $domainQueries = [];
        DB::listen(static function ($query) use (&$domainQueries): void {
            if (preg_match('/homework_assignments|wallets|wallet_ledger_entries|referral_codes|referral_rewards/', $query->sql)) {
                $domainQueries[] = $query->sql;
            }
        });

        app(StudentDashboardService::class)->summary($this->student);

        $this->assertSame([], $domainQueries);
    }

    public function test_optional_widget_failure_is_isolated(): void
    {
        $homework = Mockery::mock(HomeworkServiceInterface::class);
        $homework->shouldReceive('statsForStudent')->once()->andThrow(new \RuntimeException('Unavailable'));
        $this->app->instance(HomeworkServiceInterface::class, $homework);

        $dashboard = app(StudentDashboardService::class)->summary($this->student);

        $this->assertNull($dashboard->homework);
        $this->assertContains('homework', $dashboard->errors);
    }

    public function test_rendered_dashboard_has_no_legacy_or_dead_content(): void
    {
        $content = $this->actingAs($this->student)->get(route('dashboard'))->getContent();

        foreach (['Day 1 Streak', 'Recent Activity', 'available in a later phase', 'coming soon', 'Instructor Onboarding', 'href="#"'] as $legacy) {
            $this->assertStringNotContainsString($legacy, $content);
        }
    }

    public function test_dashboard_breadcrumb_action_has_a_minimum_touch_target(): void
    {
        $this->actingAs($this->student)->get(route('dashboard'))->assertOk()
            ->assertSee('flex min-h-11 items-center gap-1', false);
    }
}
