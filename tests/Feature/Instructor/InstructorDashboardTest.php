<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Enums\InstructorStatus;
use App\Enums\PortalAudience;
use App\Livewire\Frontend\Instructor\DashboardOverview as InstructorDashboard;
use App\Livewire\Frontend\Student\DashboardOverview as StudentDashboard;
use App\Models\Booking;
use App\Models\User;
use App\Services\FrontendPortalWorkspaceService;
use App\Services\Instructor\InstructorOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class InstructorDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
    }

    // ── Visibility ────────────────────────────────────────────────

    public function test_instructor_sees_the_instructor_dashboard(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Active);

        $this->actingAs($instructor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeLivewire(InstructorDashboard::class)
            ->assertDontSeeLivewire(StudentDashboard::class);
    }

    public function test_student_cannot_see_the_instructor_dashboard(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        $this->actingAs($student)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeLivewire(StudentDashboard::class)
            ->assertDontSeeLivewire(InstructorDashboard::class);
    }

    public function test_dual_role_user_sees_instructor_dashboard_after_selecting_that_workspace(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole(['student', 'instructor']);
        $user->profile()->update(['instructor_status' => InstructorStatus::Active]);
        $user->refresh();

        $this->assertTrue(app(FrontendPortalWorkspaceService::class)->selectWorkspace($user, PortalAudience::Instructor));

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeLivewire(InstructorDashboard::class);
    }

    // ── Onboarding prompt visibility ────────────────────────────────

    public function test_draft_instructor_sees_the_onboarding_prompt(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Draft);

        $this->actingAs($instructor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Profile readiness')
            ->assertSee('Continue setup');
    }

    public function test_approved_instructor_never_sees_the_onboarding_prompt(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Approved);

        $this->actingAs($instructor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Continue setup');
    }

    public function test_active_instructor_never_sees_the_onboarding_prompt(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Active);

        $this->actingAs($instructor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Continue setup')
            ->assertSee('Keep your schedule bookable');
    }

    public function test_force_approved_instructor_with_incomplete_profile_does_not_see_a_stale_prompt(): void
    {
        // Simulates the admin Force Approve override: instructor_status is
        // Active but the completeness checklist was never satisfied
        // (no education/experience/documents) — percentage stays under 100
        // forever unless the prompt visibility rule is status-driven, not
        // percentage-driven.
        $instructor = $this->instructorAt(InstructorStatus::Active);
        $this->assertLessThan(100, app(InstructorOnboardingService::class)->progress($instructor)['percentage']);

        $this->actingAs($instructor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Continue setup')
            ->assertDontSee('Profile readiness')
            ->assertSee('Keep your schedule bookable');
    }

    public function test_rejected_instructor_sees_a_status_message_not_a_reapply_prompt(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Rejected);

        $this->actingAs($instructor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Application not approved')
            ->assertDontSee('Continue setup')
            ->assertDontSee('Submit for review');
    }

    // ── Duplicate widget fix ─────────────────────────────────────────

    public function test_learning_plan_assessment_metric_is_shown_exactly_once(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Active);

        $response = $this->actingAs($instructor)->get(route('dashboard'))->assertOk();

        $this->assertSame(1, substr_count($response->getContent(), 'Assessments to prepare'));
        $this->assertSame(0, substr_count($response->getContent(), 'Review due'));
    }

    // ── Query safety ─────────────────────────────────────────────────

    public function test_dashboard_query_count_is_bounded_as_upcoming_bookings_grow(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Active);
        Booking::factory()->count(3)->create(['instructor_id' => $instructor->id]);
        $initial = $this->requestQueryCount($instructor);

        Booking::factory()->count(60)->create(['instructor_id' => $instructor->id]);
        $grown = $this->requestQueryCount($instructor);

        $this->assertLessThanOrEqual($initial + 2, $grown, 'Dashboard queries must stay bounded (COUNT/LIMIT), not scale with the number of upcoming bookings.');
    }

    private function requestQueryCount(User $user): int
    {
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        return $queries;
    }

    private function instructorAt(InstructorStatus $status): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);
        $instructor->assignRole('instructor');
        $instructor->profile()->update(['instructor_status' => $status]);

        return $instructor->fresh();
    }
}
