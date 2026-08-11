<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\InstructorStatus;
use App\Filament\Resources\InstructorOnboarding\InstructorOnboardingResource;
use App\Filament\Resources\InstructorOnboarding\Pages\EditInstructorOnboarding;
use App\Filament\Resources\InstructorOnboarding\Pages\ListInstructorOnboarding;
use App\Filament\Resources\InstructorOnboarding\RelationManagers\CurriculumEligibilityRelationManager;
use App\Filament\Resources\Users\RelationManagers\ActivityLogRelationManager;
use App\Filament\Resources\Users\RelationManagers\EducationsRelationManager;
use App\Filament\Resources\Users\RelationManagers\ExperiencesRelationManager;
use App\Models\User;
use App\Services\Instructor\InstructorOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A dedicated instructor-review surface, separate from the general
 * Users list. Its own edit page (EditInstructorOnboarding) owns the
 * instructor fields (InstructorOnboardingForm), the lifecycle actions
 * (HasInstructorLifecycleActions, shared with UserResource's edit page
 * so the actions themselves are never duplicated), and the
 * Experience/Education/Activity relation managers moved off
 * UserResource entirely (Activity Log is the one kept on both — see
 * InstructorTabTest).
 */
final class InstructorOnboardingResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => InstructorOnboardingService::REVIEW_PERMISSION, 'guard_name' => 'web']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('manager');
        $admin->givePermissionTo(InstructorOnboardingService::REVIEW_PERMISSION);

        return $admin;
    }

    private function instructorAt(InstructorStatus $status): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $instructor->profile()->update([
            'instructor_status' => $status,
            'instructor_application_started_at' => now()->subDays(5),
            'instructor_application_submitted_at' => $status === InstructorStatus::Draft ? null : now()->subDays(3),
        ]);

        return $instructor->fresh();
    }

    public function test_index_page_renders_for_a_permitted_admin(): void
    {
        $this->instructorAt(InstructorStatus::Submitted);

        $this->actingAs($this->admin())
            ->get(InstructorOnboardingResource::getUrl('index'))
            ->assertOk();
    }

    public function test_index_page_is_forbidden_without_review_permission(): void
    {
        $unprivileged = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $unprivileged->assignRole('manager');

        $this->actingAs($unprivileged)
            ->get(InstructorOnboardingResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_only_users_with_the_instructor_role_are_listed(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Submitted);
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student->assignRole('student');

        Livewire::actingAs($this->admin())
            ->test(ListInstructorOnboarding::class)
            ->assertCanSeeTableRecords([$instructor])
            ->assertCanNotSeeTableRecords([$student]);
    }

    public function test_navigation_badge_counts_only_statuses_needing_review(): void
    {
        $this->instructorAt(InstructorStatus::Submitted);
        $this->instructorAt(InstructorStatus::UnderReview);
        $this->instructorAt(InstructorStatus::DocumentsPending);
        $this->instructorAt(InstructorStatus::InterviewRequired);
        // None of these should count toward the badge.
        $this->instructorAt(InstructorStatus::Draft);
        $this->instructorAt(InstructorStatus::Active);
        $this->instructorAt(InstructorStatus::Rejected);

        $this->assertSame('4', InstructorOnboardingResource::getNavigationBadge());
    }

    public function test_navigation_badge_is_null_when_nothing_is_pending(): void
    {
        $this->instructorAt(InstructorStatus::Active);

        $this->assertNull(InstructorOnboardingResource::getNavigationBadge());
    }

    public function test_needs_review_tab_only_shows_statuses_awaiting_admin_action(): void
    {
        $pending = $this->instructorAt(InstructorStatus::UnderReview);
        $active = $this->instructorAt(InstructorStatus::Active);

        Livewire::actingAs($this->admin())
            ->test(ListInstructorOnboarding::class)
            ->set('activeTab', 'needs_review')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$active]);
    }

    public function test_review_row_action_links_to_the_dedicated_edit_page(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Submitted);

        Livewire::actingAs($this->admin())
            ->test(ListInstructorOnboarding::class)
            ->assertTableActionHasUrl('review', InstructorOnboardingResource::getUrl('edit', ['record' => $instructor]), $instructor);
    }

    public function test_edit_page_renders_and_shows_only_instructor_fields(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Submitted);

        $this->actingAs($this->admin())
            ->get(InstructorOnboardingResource::getUrl('edit', ['record' => $instructor]))
            ->assertOk()
            ->assertSee('Professional Profile')
            ->assertSee('Marketplace Controls')
            ->assertSee('Application completeness')
            // None of UserResource's unrelated tabs exist on this page.
            ->assertDontSee('General Information')
            ->assertDontSee('Assign Roles')
            ->assertDontSee('Security');
    }

    public function test_edit_page_exposes_the_same_lifecycle_actions(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::UnderReview);

        Livewire::actingAs($this->admin())
            ->test(EditInstructorOnboarding::class, ['record' => $instructor->getRouteKey()])
            ->assertActionExists('approveInstructor')
            ->assertActionExists('rejectInstructor')
            ->assertActionExists('markInstructorInterviewRequired');
    }

    public function test_approving_from_the_dedicated_page_actually_updates_the_instructor(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::UnderReview);

        Livewire::actingAs($this->admin())
            ->test(EditInstructorOnboarding::class, ['record' => $instructor->getRouteKey()])
            ->callAction('approveInstructor', data: ['reason' => 'Looks good.']);

        $this->assertSame(InstructorStatus::Approved, $instructor->fresh()->profile->instructor_status);
    }

    public function test_featured_toggle_persists_to_user_profile(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Active);
        $this->assertFalse((bool) $instructor->profile->is_featured);

        Livewire::actingAs($this->admin())
            ->test(EditInstructorOnboarding::class, ['record' => $instructor->getRouteKey()])
            ->set('data.profile.is_featured', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue((bool) $instructor->profile->fresh()->is_featured);
    }

    public function test_instructor_verified_toggle_persists(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Active);

        Livewire::actingAs($this->admin())
            ->test(EditInstructorOnboarding::class, ['record' => $instructor->getRouteKey()])
            ->set('data.profile.is_instructor_verified', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue((bool) $instructor->profile->fresh()->is_instructor_verified);
    }

    public function test_instructor_status_is_read_only_on_the_dedicated_page(): void
    {
        $instructor = $this->instructorAt(InstructorStatus::Submitted);

        Livewire::actingAs($this->admin())
            ->test(EditInstructorOnboarding::class, ['record' => $instructor->getRouteKey()])
            ->assertSee('Application lifecycle')
            ->assertDontSeeHtml('name="data.profile.instructor_status"');
    }

    public function test_experience_education_and_activity_moved_here_from_users(): void
    {
        $this->assertSame([
            ExperiencesRelationManager::class,
            EducationsRelationManager::class,
            CurriculumEligibilityRelationManager::class,
            ActivityLogRelationManager::class,
        ], InstructorOnboardingResource::getRelations());
    }

    // ── Navigation badge resilience when the instructor role row doesn't exist ──

    /**
     * Filament evaluates every registered resource's getNavigationBadge()
     * on every admin page load — not just this resource's own pages.
     * Before this fix, pendingReviewQuery() used the Spatie `role()`
     * scope, which resolves the role name via Role::findByName() and
     * throws RoleDoesNotExist when that row is absent (fresh install,
     * partial deployment, an out-of-order seeder run) — crashing
     * completely unrelated admin pages with a 500. This must degrade to
     * "nothing pending", never a fatal error.
     */
    public function test_navigation_badge_returns_null_and_does_not_throw_when_the_instructor_role_row_is_absent(): void
    {
        // setUp() seeds 'instructor' for the rest of this file's tests —
        // remove it here to reproduce the exact fresh-install/partial-
        // deployment condition this fix targets.
        Role::where('name', 'instructor')->where('guard_name', 'web')->delete();

        $this->assertNull(InstructorOnboardingResource::getNavigationBadge());
    }

    public function test_navigation_badge_read_never_creates_the_instructor_role(): void
    {
        Role::where('name', 'instructor')->where('guard_name', 'web')->delete();

        InstructorOnboardingResource::getNavigationBadge();

        $this->assertFalse(
            Role::where('name', 'instructor')->where('guard_name', 'web')->exists(),
            'A read-only badge computation must never create the missing role as a side effect.'
        );
    }

    /**
     * The prior (unfixed) failure mode was specifically that UNRELATED
     * admin pages 500'd because Filament evaluates every resource's nav
     * badge on every page load — proven here directly against a
     * completely unrelated settings page, with no role pre-seeded at
     * all.
     */
    public function test_an_unrelated_settings_page_renders_without_the_instructor_role_seeded(): void
    {
        Role::where('name', 'instructor')->where('guard_name', 'web')->delete();

        $superAdmin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $superAdmin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

        $this->actingAs($superAdmin)
            ->get('/admin/settings/general')
            ->assertOk();
    }
}
