<?php

declare(strict_types=1);

namespace Tests\Feature\AccountPortal;

use App\Livewire\Frontend\Instructor\DashboardOverview as InstructorDashboard;
use App\Livewire\Frontend\Student\DashboardOverview as StudentDashboard;
use App\Models\Booking;
use App\Models\User;
use App\Services\Instructor\InstructorOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class Phase20BPortalShellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
    }

    public function test_student_request_never_resolves_instructor_onboarding_and_has_no_instructor_output(): void
    {
        $student = $this->user('student');
        $this->app->bind(InstructorOnboardingService::class, static fn () => throw new \RuntimeException('Instructor onboarding was resolved for a student request.'));

        $this->actingAs($student)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeLivewire(StudentDashboard::class)
            ->assertDontSeeLivewire(InstructorDashboard::class)
            ->assertDontSee('Instructor Onboarding')
            ->assertDontSee('Instructor Progress')
            ->assertDontSee('Availability')
            ->assertDontSee('Payout Methods')
            ->assertDontSee('Withdrawals');
    }

    public function test_instructor_and_dual_role_follow_the_approved_audiences(): void
    {
        $instructor = $this->user('instructor');
        $dual = $this->user('student');
        $dual->assignRole('instructor');

        $this->actingAs($instructor)->get(route('dashboard'))
            ->assertOk()->assertSeeLivewire(InstructorDashboard::class);

        $this->actingAs($dual)->get(route('dashboard'))
            ->assertOk()->assertSeeLivewire(StudentDashboard::class)
            ->assertDontSeeLivewire(InstructorDashboard::class);
    }

    public function test_unsupported_frontend_account_uses_the_existing_safe_student_fallback(): void
    {
        $this->actingAs(User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeLivewire(StudentDashboard::class)
            ->assertDontSeeLivewire(InstructorDashboard::class);
    }

    public function test_authenticated_shell_has_grouped_desktop_drawer_and_mobile_navigation_but_no_public_chrome(): void
    {
        $response = $this->actingAs($this->user('student'))->get(route('dashboard'));

        $response->assertOk()
            ->assertSeeInOrder(['Overview', 'Learn', 'Discover', 'Money', 'Engage', 'Account'])
            ->assertSee('data-account-sidebar-mode="desktop"', false)
            ->assertSee('data-account-sidebar-mode="drawer"', false)
            ->assertSee('data-account-mobile-navigation', false)
            ->assertSee('role="dialog"', false)
            ->assertSee('aria-modal="true"', false)
            ->assertSee('<main id="main-content"', false)
            ->assertSee('data-account-footer', false)
            ->assertDontSee('Open search')
            ->assertDontSee('Latest Posts')
            ->assertDontSee('Footer navigation is not configured');

        $this->assertSame(2, substr_count($response->getContent(), 'data-account-menu-item="dashboard.wishlist"'));
        $this->assertStringNotContainsString('href="#"', $response->getContent());
        $this->assertMatchesRegularExpression('/<form method="POST" action="[^\"]+\/logout">/', $response->getContent());
    }

    public function test_dashboard_and_livewire_query_counts_are_bounded_as_history_grows(): void
    {
        $student = $this->user('student');
        $initial = $this->requestQueryCount($student);

        Booking::factory()->count(40)->create(['student_id' => $student->id]);
        $grown = $this->requestQueryCount($student);

        $this->assertLessThanOrEqual(30, $initial);
        $this->assertLessThanOrEqual($initial + 2, $grown);

        $componentQueries = 0;
        DB::listen(static function () use (&$componentQueries): void {
            $componentQueries++;
        });
        Livewire::actingAs($student)->test(StudentDashboard::class)->assertOk();
        $this->assertLessThanOrEqual(15, $componentQueries);
    }

    public function test_instructor_dashboard_query_count_is_bounded(): void
    {
        $queries = $this->requestQueryCount($this->user('instructor'));

        $this->assertLessThanOrEqual(30, $queries);
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

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);
        $user->assignRole($role);

        return $user;
    }
}
