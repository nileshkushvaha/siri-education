<?php

declare(strict_types=1);

namespace Tests\Feature\Roles;

use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Duplicating a role copies the ORIGINAL role's full permission set onto
 * the new role — that's a permission assignment, not just a copy, so it
 * must respect the same AssignPermissions:Role gate as EditRole/CreateRole.
 * Replicate:Role alone must not be a side-door around that gate.
 */
class RoleReplicateActionTest extends TestCase
{
    use RefreshDatabase;

    private Role $targetRole;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ViewAny:Role', 'View:Role', 'Replicate:Role', 'AssignPermissions:Role'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->targetRole = Role::create(['name' => 'source-role', 'guard_name' => 'web']);
        $this->targetRole->givePermissionTo(['ViewAny:Role']);
    }

    public function test_user_without_assign_permissions_cannot_copy_permissions_via_duplicate(): void
    {
        $managerRole = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager = User::factory()->create(['status' => 'active']);
        $manager->assignRole($managerRole);
        $manager->givePermissionTo(['ViewAny:Role', 'View:Role', 'Replicate:Role']);

        $this->actingAs($manager);

        Livewire::test(ListRoles::class)
            ->callTableAction('replicate', $this->targetRole, data: ['name' => 'copied-role']);

        $replica = Role::where('name', 'copied-role')->firstOrFail();
        $this->assertSame([], $replica->permissions->pluck('name')->all());
    }

    public function test_user_with_assign_permissions_can_copy_permissions_via_duplicate(): void
    {
        $managerRole = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager = User::factory()->create(['status' => 'active']);
        $manager->assignRole($managerRole);
        $manager->givePermissionTo(['ViewAny:Role', 'View:Role', 'Replicate:Role', 'AssignPermissions:Role']);

        $this->actingAs($manager);

        Livewire::test(ListRoles::class)
            ->callTableAction('replicate', $this->targetRole, data: ['name' => 'copied-role-2']);

        $replica = Role::where('name', 'copied-role-2')->firstOrFail();
        $this->assertSame(['ViewAny:Role'], $replica->permissions->pluck('name')->all());
    }
}
