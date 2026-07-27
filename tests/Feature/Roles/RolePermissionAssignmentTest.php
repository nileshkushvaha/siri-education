<?php

declare(strict_types=1);

namespace Tests\Feature\Roles;

use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * AssignPermissions:Role gates the Permission Assignment matrix on the Role
 * form separately from Update:Role/Create:Role. Both the UI visibility and
 * the server-side mutation (EditRole::afterSave / CreateRole::afterCreate)
 * must agree — a hidden section alone is not a security boundary, since
 * selectedPermissions is a public Livewire property that could otherwise be
 * tampered with directly.
 */
class RolePermissionAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $managerWithAssign;

    private User $managerWithoutAssign;

    private Role $targetRole;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ViewAny:Role', 'View:Role', 'Create:Role', 'Update:Role', 'AssignPermissions:Role'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $managerRole = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        // InstructorOnboardingResource::getNavigationBadge()
        // scopes User::role('instructor') on every admin page load (Filament
        // evaluates every resource's nav badge on every page, not just its own).
        // Without this role seeded, any admin panel page in this test throws
        // RoleDoesNotExist — a test-isolation gap, not a role/permission
        // behavior this test is meant to cover. Same fix pattern used
        // elsewhere in this suite for the identical root cause.
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $this->superAdmin->assignRole($superAdminRole);

        // Both manager users need the manager role for Admin Portal access
        // (PortalResolver); they differ only in the AssignPermissions:Role gate.
        $this->managerWithAssign = User::factory()->create(['status' => 'active']);
        $this->managerWithAssign->assignRole($managerRole);
        $this->managerWithAssign->givePermissionTo(['ViewAny:Role', 'View:Role', 'Create:Role', 'Update:Role', 'AssignPermissions:Role']);

        $this->managerWithoutAssign = User::factory()->create(['status' => 'active']);
        $this->managerWithoutAssign->assignRole($managerRole);
        $this->managerWithoutAssign->givePermissionTo(['ViewAny:Role', 'View:Role', 'Create:Role', 'Update:Role']);

        $this->targetRole = Role::create(['name' => 'editable-role', 'guard_name' => 'web']);
        $this->targetRole->givePermissionTo('ViewAny:Role');
    }

    // ── UI visibility ───────────────────────────────────────────────────────

    public function test_super_admin_sees_permission_assignment_section(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('filament.admin.resources.roles.edit', $this->targetRole))
            ->assertOk()
            ->assertSee('Permission Assignment');
    }

    public function test_user_with_assign_permission_sees_permission_assignment_section(): void
    {
        $this->actingAs($this->managerWithAssign)
            ->get(route('filament.admin.resources.roles.edit', $this->targetRole))
            ->assertOk()
            ->assertSee('Permission Assignment');
    }

    public function test_user_without_assign_permission_does_not_see_permission_assignment_section(): void
    {
        $this->actingAs($this->managerWithoutAssign)
            ->get(route('filament.admin.resources.roles.edit', $this->targetRole))
            ->assertOk()
            ->assertDontSee('Permission Assignment');
    }

    // ── Server-side mutation guard (EditRole) ────────────────────────────────

    public function test_user_without_assign_permission_cannot_change_role_permissions_via_tampered_payload(): void
    {
        $this->actingAs($this->managerWithoutAssign);

        Livewire::test(EditRole::class, ['record' => $this->targetRole->getRouteKey()])
            ->set('selectedPermissions', ['ViewAny:Role', 'Create:Role', 'Update:Role'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            ['ViewAny:Role'],
            $this->targetRole->fresh()->permissions->pluck('name')->all(),
            'A user without AssignPermissions:Role must not be able to change a role\'s permissions.'
        );
    }

    public function test_user_with_assign_permission_can_change_role_permissions(): void
    {
        $this->actingAs($this->managerWithAssign);

        Livewire::test(EditRole::class, ['record' => $this->targetRole->getRouteKey()])
            ->set('selectedPermissions', ['ViewAny:Role', 'Create:Role'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(
            ['ViewAny:Role', 'Create:Role'],
            $this->targetRole->fresh()->permissions->pluck('name')->all()
        );
    }

    public function test_super_admin_can_change_role_permissions(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(EditRole::class, ['record' => $this->targetRole->getRouteKey()])
            ->set('selectedPermissions', ['ViewAny:Role', 'Update:Role'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(
            ['ViewAny:Role', 'Update:Role'],
            $this->targetRole->fresh()->permissions->pluck('name')->all()
        );
    }

    public function test_edit_form_does_not_prefill_permissions_for_user_without_assign_permission(): void
    {
        $this->actingAs($this->managerWithoutAssign);

        Livewire::test(EditRole::class, ['record' => $this->targetRole->getRouteKey()])
            ->assertSet('selectedPermissions', []);
    }

    public function test_edit_form_prefills_permissions_for_user_with_assign_permission(): void
    {
        $this->actingAs($this->managerWithAssign);

        Livewire::test(EditRole::class, ['record' => $this->targetRole->getRouteKey()])
            ->assertSet('selectedPermissions', ['ViewAny:Role']);
    }

    // ── Server-side mutation guard (CreateRole) ──────────────────────────────

    public function test_user_without_assign_permission_cannot_set_permissions_on_create(): void
    {
        $this->actingAs($this->managerWithoutAssign);

        Livewire::test(CreateRole::class)
            ->fillForm(['name' => 'new-role-no-perms', 'guard_name' => 'web'])
            ->set('selectedPermissions', ['ViewAny:Role', 'Create:Role'])
            ->call('create')
            ->assertHasNoFormErrors();

        $role = Role::where('name', 'new-role-no-perms')->firstOrFail();

        $this->assertSame([], $role->permissions->pluck('name')->all());
    }

    public function test_user_with_assign_permission_can_set_permissions_on_create(): void
    {
        $this->actingAs($this->managerWithAssign);

        Livewire::test(CreateRole::class)
            ->fillForm(['name' => 'new-role-with-perms', 'guard_name' => 'web'])
            ->set('selectedPermissions', ['ViewAny:Role', 'Create:Role'])
            ->call('create')
            ->assertHasNoFormErrors();

        $role = Role::where('name', 'new-role-with-perms')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            ['ViewAny:Role', 'Create:Role'],
            $role->permissions->pluck('name')->all()
        );
    }
}
