<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 24E — GAP-010/SRS-23-7: the canonical super_admin Role record
 * cannot be deleted or incompatibly renamed, driven through the real
 * Filament Role resource pages.
 */
class SuperAdminProtectionRolesTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('super_admin');
        $this->actingAs($this->actor);
    }

    public function test_the_canonical_super_admin_role_cannot_be_deleted_via_the_table(): void
    {
        $role = Role::where('name', 'super_admin')->firstOrFail();

        Livewire::test(ListRoles::class)
            ->callTableAction('delete', $role);

        $this->assertNotNull(Role::query()->find($role->id));
        // Phase 24Q — GAP-011: a blocked mutation must never create a
        // misleading success audit entry.
        $this->assertSame(0, Activity::query()->where('log_name', 'roles')->where('event', 'deleted')->where('subject_id', $role->id)->count());
    }

    public function test_a_non_canonical_role_can_be_deleted_via_the_table(): void
    {
        $role = Role::firstOrCreate(['name' => 'disposable-role', 'guard_name' => 'web']);
        $roleId = $role->id;

        Livewire::test(ListRoles::class)
            ->callTableAction('delete', $role);

        $this->assertNull(Role::query()->find($roleId));
        // Phase 24Q — GAP-011: a successful safe mutation creates exactly one audit event.
        $this->assertSame(1, Activity::query()->where('log_name', 'roles')->where('event', 'deleted')->where('subject_id', $roleId)->count());
    }

    public function test_bulk_deleting_roles_including_the_canonical_role_is_rejected_atomically(): void
    {
        $superAdminRole = Role::where('name', 'super_admin')->firstOrFail();
        $other = Role::firstOrCreate(['name' => 'disposable-role-a', 'guard_name' => 'web']);

        Livewire::test(ListRoles::class)
            ->callTableBulkAction('delete', collect([$superAdminRole, $other]));

        $this->assertNotNull(Role::query()->find($superAdminRole->id));
        $this->assertNotNull(Role::query()->find($other->id), 'The whole batch must roll back, including the unrelated role.');
        // Phase 24Q — GAP-011: the whole batch is rejected — no audit
        // entry for either role, including the one that wasn't canonical.
        $this->assertSame(0, Activity::query()->where('log_name', 'roles')->where('event', 'deleted')->whereIn('subject_id', [$superAdminRole->id, $other->id])->count());
    }

    public function test_bulk_deleting_roles_without_the_canonical_role_succeeds(): void
    {
        $a = Role::firstOrCreate(['name' => 'disposable-role-a', 'guard_name' => 'web']);
        $b = Role::firstOrCreate(['name' => 'disposable-role-b', 'guard_name' => 'web']);

        Livewire::test(ListRoles::class)
            ->callTableBulkAction('delete', collect([$a, $b]));

        $this->assertNull(Role::query()->find($a->id));
        $this->assertNull(Role::query()->find($b->id));
        $this->assertSame(2, Activity::query()->where('log_name', 'roles')->where('event', 'deleted')->whereIn('subject_id', [$a->id, $b->id])->count());
    }

    public function test_the_canonical_role_cannot_be_renamed_incompatibly(): void
    {
        $role = Role::where('name', 'super_admin')->firstOrFail();

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['name' => 'super-admin-renamed', 'guard_name' => 'web'])
            ->call('save');

        $this->assertSame('super_admin', $role->fresh()->name);
        $this->assertSame(0, Activity::query()->where('log_name', 'roles')->where('event', 'updated')->where('subject_id', $role->id)->count());
    }

    public function test_the_canonical_role_may_be_saved_with_its_own_unchanged_name(): void
    {
        $role = Role::where('name', 'super_admin')->firstOrFail();

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['name' => 'super_admin', 'guard_name' => 'web', 'description' => 'Updated description'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('super_admin', $role->fresh()->name);
        $this->assertSame('Updated description', $role->fresh()->description);
        // A real (non-identity) field changed — this is a committed
        // change, not a no-op, and must produce exactly one audit event.
        $this->assertSame(1, Activity::query()->where('log_name', 'roles')->where('event', 'updated')->where('subject_id', $role->id)->count());
    }

    public function test_a_non_canonical_role_can_be_renamed_freely(): void
    {
        $role = Role::firstOrCreate(['name' => 'old-name', 'guard_name' => 'web']);

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['name' => 'new-name', 'guard_name' => 'web'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('new-name', $role->fresh()->name);

        $activity = Activity::query()->where('log_name', 'roles')->where('event', 'updated')->where('subject_id', $role->id)->sole();
        $this->assertSame('old-name', $activity->properties['previous_name']);
        $this->assertSame('new-name', $activity->properties['new_name']);
    }
}
