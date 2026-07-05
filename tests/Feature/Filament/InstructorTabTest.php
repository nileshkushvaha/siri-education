<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Verifies the Instructor tab in UserResource behaves correctly — hidden for
 * non-instructor users, visible for instructor-role users, and keeps status
 * transitions on reason-required review actions instead of a generic select.
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

    public function test_instructor_tab_is_hidden_for_non_instructor_user(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        // When the user does not have the instructor role the instructor controls
        // (is_featured, is_instructor_verified) must not be rendered at all.
        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertDontSee('Featured Instructor')
            ->assertDontSee('Verified Instructor')
            ->assertDontSee('Profile Status');
    }

    public function test_instructor_tab_visible_for_instructor_role_user(): void
    {
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');

        Livewire::test(EditUser::class, ['record' => $instructor->getRouteKey()])
            ->assertSee('Featured Instructor')
            ->assertSee('Verified Instructor');
    }

    public function test_featured_toggle_persists_to_user_profile(): void
    {
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');
        $this->assertFalse((bool) $instructor->profile->is_featured);

        Livewire::test(EditUser::class, ['record' => $instructor->getRouteKey()])
            ->set('data.profile.is_featured', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue((bool) $instructor->profile->fresh()->is_featured);
    }

    public function test_instructor_verified_toggle_persists(): void
    {
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');

        Livewire::test(EditUser::class, ['record' => $instructor->getRouteKey()])
            ->set('data.profile.is_instructor_verified', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue((bool) $instructor->profile->fresh()->is_instructor_verified);
    }

    public function test_instructor_status_is_read_only_in_generic_form(): void
    {
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');

        Livewire::test(EditUser::class, ['record' => $instructor->getRouteKey()])
            ->assertSee('Profile Status')
            ->assertDontSeeHtml('name="data.profile.instructor_status"');

        $this->assertNull($instructor->profile->fresh()->instructor_status);
    }
}
