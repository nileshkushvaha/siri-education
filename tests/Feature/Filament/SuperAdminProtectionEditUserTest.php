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
 * Phase 24E — GAP-010/SRS-23-7: the authoritative EditUser guard, driven
 * through the real Filament Livewire form (not the service directly),
 * proving the UI cannot bypass SuperAdminGuardService.
 */
class SuperAdminProtectionEditUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('super_admin');

        return $user;
    }

    /** @return array{roles: list<int>} */
    private function roleIds(string ...$names): array
    {
        return ['roles' => Role::query()->whereIn('name', $names)->pluck('id')->all()];
    }

    public function test_the_only_active_super_admin_cannot_have_the_role_removed_via_the_form(): void
    {
        $actor = $this->superAdmin();
        $this->actingAs($actor);

        Livewire::test(EditUser::class, ['record' => $actor->getRouteKey()])
            ->fillForm([
                'name' => $actor->name,
                'email' => $actor->email,
                'status' => 'active',
                ...$this->roleIds('manager'),
            ])
            ->call('save');

        $this->assertTrue($actor->fresh()->hasRole('super_admin'), 'The role removal must have been rolled back.');
    }

    public function test_the_only_active_super_admin_cannot_be_deactivated_via_the_form(): void
    {
        // Self-action: with only one Super Admin in existence, they are
        // necessarily the only possible actor able to reach this page.
        $actor = $this->superAdmin();
        $this->actingAs($actor);

        Livewire::test(EditUser::class, ['record' => $actor->getRouteKey()])
            ->fillForm([
                'name' => $actor->name,
                'email' => $actor->email,
                'status' => 'inactive',
                ...$this->roleIds('super_admin'),
            ])
            ->call('save');

        $this->assertSame(User::STATUS_ACTIVE, $actor->fresh()->status, 'The deactivation must have been rolled back.');
    }

    public function test_a_super_admin_can_be_demoted_via_the_form_when_another_active_super_admin_remains(): void
    {
        $target = $this->superAdmin();
        $other = $this->superAdmin();
        $this->actingAs($other);

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm([
                'name' => $target->name,
                'email' => $target->email,
                'status' => 'active',
                ...$this->roleIds('manager'),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($target->fresh()->hasRole('super_admin'));
    }

    public function test_a_super_admin_can_be_deactivated_via_the_form_when_another_active_super_admin_remains(): void
    {
        $target = $this->superAdmin();
        $other = $this->superAdmin();
        $this->actingAs($other);

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm([
                'name' => $target->name,
                'email' => $target->email,
                'status' => 'inactive',
                ...$this->roleIds('super_admin'),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(User::STATUS_INACTIVE, $target->fresh()->status);
    }

    public function test_self_demotion_is_blocked_when_the_actor_is_the_last_active_super_admin(): void
    {
        $actor = $this->superAdmin();
        $this->actingAs($actor);

        Livewire::test(EditUser::class, ['record' => $actor->getRouteKey()])
            ->fillForm([
                'name' => $actor->name,
                'email' => $actor->email,
                'status' => 'active',
                ...$this->roleIds('manager'),
            ])
            ->call('save');

        $this->assertTrue($actor->fresh()->hasRole('super_admin'));
    }

    public function test_self_demotion_follows_existing_policy_when_another_active_super_admin_remains(): void
    {
        $actor = $this->superAdmin();
        $this->superAdmin();
        $this->actingAs($actor);

        Livewire::test(EditUser::class, ['record' => $actor->getRouteKey()])
            ->fillForm([
                'name' => $actor->name,
                'email' => $actor->email,
                'status' => 'active',
                ...$this->roleIds('manager'),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($actor->fresh()->hasRole('super_admin'));
    }

    public function test_a_stale_form_cannot_bypass_the_invariant_after_the_other_admin_already_lost_access(): void
    {
        $target = $this->superAdmin();
        $other = $this->superAdmin();
        $this->actingAs($other);

        $form = Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm([
                'name' => $target->name,
                'email' => $target->email,
                'status' => 'inactive',
                ...$this->roleIds('super_admin'),
            ]);

        // Between the form being opened/filled and its submission, the
        // OTHER Super Admin independently lost access.
        $other->update(['status' => User::STATUS_INACTIVE]);

        $form->call('save');

        $this->assertSame(
            User::STATUS_ACTIVE,
            $target->fresh()->status,
            'The stale form must not be able to leave zero active Super Admins.',
        );
    }

    public function test_unrelated_profile_edits_for_the_only_super_admin_remain_allowed(): void
    {
        $actor = $this->superAdmin();
        $this->actingAs($actor);

        Livewire::test(EditUser::class, ['record' => $actor->getRouteKey()])
            ->fillForm([
                'name' => 'Renamed Via Form',
                'email' => $actor->email,
                'status' => 'active',
                ...$this->roleIds('super_admin'),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Renamed Via Form', $actor->fresh()->name);
    }

    public function test_adding_a_role_to_the_only_super_admin_remains_allowed(): void
    {
        $actor = $this->superAdmin();
        $this->actingAs($actor);

        Livewire::test(EditUser::class, ['record' => $actor->getRouteKey()])
            ->fillForm([
                'name' => $actor->name,
                'email' => $actor->email,
                'status' => 'active',
                ...$this->roleIds('super_admin', 'manager'),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $actor->fresh();
        $this->assertTrue($fresh->hasRole('super_admin'));
        $this->assertTrue($fresh->hasRole('manager'));
    }

    public function test_unauthorized_non_super_admin_cannot_edit_admin_users(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $this->actingAs($student);

        $target = $this->superAdmin();

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->assertForbidden();
    }
}
