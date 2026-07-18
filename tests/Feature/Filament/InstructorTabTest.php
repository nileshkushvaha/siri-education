<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\InstructorStatus;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The instructor-only onboarding content (the old "Instructor" tab,
 * lifecycle actions, Experience/Education) now lives solely on
 * InstructorOnboardingResource — see InstructorOnboardingResourceTest.
 * Activity Log is the one relation manager kept on both pages, since
 * it's general account activity, not instructor-specific. This file
 * guards that the instructor-only content didn't leak back here.
 */
class InstructorTabTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $this->superAdmin->assignRole('super_admin');
        $this->actingAs($this->superAdmin);
    }

    public function test_instructor_fields_are_absent_for_non_instructor_user(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertDontSee('Featured Instructor')
            ->assertDontSee('Verified Instructor')
            ->assertDontSee('Profile Status');
    }

    public function test_instructor_fields_are_absent_even_for_an_instructor_role_user(): void
    {
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');

        Livewire::test(EditUser::class, ['record' => $instructor->getRouteKey()])
            ->assertDontSee('Featured Instructor')
            ->assertDontSee('Verified Instructor')
            ->assertDontSee('Profile Status')
            ->assertDontSee('Instructor Profile Review')
            ->assertDontSee('Instructor Controls')
            ->assertDontSee('Verification Documents');
    }

    public function test_no_instructor_lifecycle_actions_on_the_users_edit_page(): void
    {
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');
        $instructor->profile()->update(['instructor_status' => InstructorStatus::UnderReview]);

        Livewire::test(EditUser::class, ['record' => $instructor->getRouteKey()])
            ->assertActionDoesNotExist('approveInstructor')
            ->assertActionDoesNotExist('rejectInstructor')
            ->assertActionDoesNotExist('markInstructorUnderReview');
    }

    public function test_activity_log_is_still_shown_on_the_users_edit_page(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($this->superAdmin)
            ->get(UserResource::getUrl('edit', ['record' => $user]))
            ->assertOk()
            ->assertSee('Activity');
    }
}
