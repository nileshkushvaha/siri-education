<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Enums\InstructorStatus;
use App\Models\User;
use App\Services\Account\AccountMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An instructor who has the `instructor` role but hasn't cleared review
 * (or is suspended/archived) must not reach the teaching workspace
 * (lessons, students, earnings, analytics, etc.) — only the application
 * status page. Before this, every workspace controller checked
 * hasRole('instructor') only, so any applicant — regardless of
 * InstructorStatus — had full workspace access.
 */
final class InstructorWorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
    }

    private function instructorAt(InstructorStatus $status): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $instructor->profile()->update(['instructor_status' => $status]);

        return $instructor->fresh();
    }

    public static function nonWorkspaceEligibleStatusProvider(): array
    {
        return [
            'draft' => [InstructorStatus::Draft],
            'submitted' => [InstructorStatus::Submitted],
            'under_review' => [InstructorStatus::UnderReview],
            'documents_pending' => [InstructorStatus::DocumentsPending],
            'interview_required' => [InstructorStatus::InterviewRequired],
            'suspended' => [InstructorStatus::Suspended],
            'archived' => [InstructorStatus::Archived],
            'rejected' => [InstructorStatus::Rejected],
        ];
    }

    public static function workspaceEligibleStatusProvider(): array
    {
        return [
            'approved' => [InstructorStatus::Approved],
            'active' => [InstructorStatus::Active],
            'vacation' => [InstructorStatus::Vacation],
        ];
    }

    #[DataProvider('nonWorkspaceEligibleStatusProvider')]
    public function test_non_eligible_instructor_is_redirected_from_workspace_routes(InstructorStatus $status): void
    {
        $instructor = $this->instructorAt($status);

        foreach ([
            'dashboard.instructor.availability',
            'dashboard.instructor.lessons',
            'dashboard.instructor.students',
            'dashboard.instructor.homework',
            'dashboard.instructor.quality-insights',
            'dashboard.instructor.analytics',
            'dashboard.instructor.earnings',
            'dashboard.instructor.settlements',
            'dashboard.instructor.payout-methods',
            'dashboard.instructor.withdrawals',
            'dashboard.instructor.vacation',
            'dashboard.instructor.learning-plans',
        ] as $route) {
            $this->actingAs($instructor)
                ->get(route($route))
                ->assertRedirect(route('dashboard.instructor.onboarding'));
        }
    }

    #[DataProvider('workspaceEligibleStatusProvider')]
    public function test_eligible_instructor_can_reach_workspace_routes(InstructorStatus $status): void
    {
        $instructor = $this->instructorAt($status);

        $this->actingAs($instructor)
            ->get(route('dashboard.instructor.availability'))
            ->assertOk();
    }

    #[DataProvider('nonWorkspaceEligibleStatusProvider')]
    public function test_non_eligible_instructor_can_still_reach_the_onboarding_page(InstructorStatus $status): void
    {
        $instructor = $this->instructorAt($status);

        $this->actingAs($instructor)
            ->get(route('dashboard.instructor.onboarding'))
            ->assertOk();
    }

    #[DataProvider('nonWorkspaceEligibleStatusProvider')]
    public function test_non_eligible_instructor_sidebar_hides_the_teaching_workspace(InstructorStatus $status): void
    {
        $instructor = $this->instructorAt($status);

        $labels = collect(app(AccountMenuService::class)->items($instructor))
            ->flatMap(fn (array $group): array => $group['items'])
            ->pluck('label');

        $this->assertContains('Dashboard', $labels);
        $this->assertContains('Application Status', $labels);
        $this->assertNotContains('Availability', $labels);
        $this->assertNotContains('My Lessons', $labels);
        $this->assertNotContains('Students', $labels);
        $this->assertNotContains('Earnings', $labels);
        $this->assertNotContains('Analytics', $labels);
    }

    #[DataProvider('workspaceEligibleStatusProvider')]
    public function test_eligible_instructor_sidebar_shows_the_teaching_workspace(InstructorStatus $status): void
    {
        $instructor = $this->instructorAt($status);

        $labels = collect(app(AccountMenuService::class)->items($instructor))
            ->flatMap(fn (array $group): array => $group['items'])
            ->pluck('label');

        $this->assertContains('Availability', $labels);
        $this->assertContains('My Lessons', $labels);
        $this->assertContains('Students', $labels);
        $this->assertContains('Earnings', $labels);
        $this->assertContains('Analytics', $labels);
    }
}
