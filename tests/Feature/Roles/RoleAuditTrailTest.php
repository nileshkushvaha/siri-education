<?php

declare(strict_types=1);

namespace Tests\Feature\Roles;

use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Models\Activity;
use App\Models\User;
use App\Services\Admin\RoleAuditRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 24Q — GAP-011/SRS-23-6: every Role/Permission mutation surface
 * (CreateRole, EditRole, RolesTable row/bulk/replicate actions) now
 * records through RoleAuditRecorder -> AuditTrailService instead of
 * calling activity() directly. This file proves the resulting events are
 * accurate, non-duplicated, and absent exactly when nothing committed.
 */
class RoleAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $managerWithAssign;

    private User $managerWithoutAssign;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ViewAny:Role', 'View:Role', 'Create:Role', 'Update:Role', 'Delete:Role', 'DeleteAny:Role', 'Replicate:Role', 'AssignPermissions:Role'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        Permission::firstOrCreate(['name' => 'some-permission', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'another-permission', 'guard_name' => 'web']);

        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $managerRole = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        // InstructorOnboardingResource::getNavigationBadge() scopes
        // User::role('instructor') on every admin page load.
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $this->superAdmin->assignRole($superAdminRole);

        $this->managerWithAssign = User::factory()->create(['status' => 'active']);
        $this->managerWithAssign->assignRole($managerRole);
        $this->managerWithAssign->givePermissionTo(['ViewAny:Role', 'View:Role', 'Create:Role', 'Update:Role', 'Delete:Role', 'DeleteAny:Role', 'Replicate:Role', 'AssignPermissions:Role']);

        $this->managerWithoutAssign = User::factory()->create(['status' => 'active']);
        $this->managerWithoutAssign->assignRole($managerRole);
        $this->managerWithoutAssign->givePermissionTo(['ViewAny:Role', 'View:Role', 'Create:Role', 'Update:Role', 'Delete:Role', 'DeleteAny:Role', 'Replicate:Role']);
    }

    private function roleActivities(?string $event = null): Collection
    {
        return Activity::query()
            ->where('log_name', 'roles')
            ->when($event, fn ($q) => $q->where('event', $event))
            ->get();
    }

    // ── 1. Role creation ─────────────────────────────────────────────────

    public function test_role_creation_records_one_created_event_with_correct_structure(): void
    {
        $this->actingAs($this->managerWithAssign);

        Livewire::test(CreateRole::class)
            ->fillForm(['name' => 'brand-new-role', 'guard_name' => 'web'])
            ->set('selectedPermissions', ['some-permission', 'another-permission'])
            ->call('create')
            ->assertHasNoFormErrors();

        $role = Role::where('name', 'brand-new-role')->firstOrFail();
        $events = $this->roleActivities('created')->where('subject_id', $role->id);

        $this->assertCount(1, $events, 'Exactly one created event, no duplicates.');

        $activity = $events->first();
        $this->assertSame($this->managerWithAssign->id, $activity->causer_id);
        $this->assertSame($role->id, $activity->properties['role_id']);
        $this->assertSame('brand-new-role', $activity->properties['role_name']);
        $this->assertSame(['another-permission', 'some-permission'], $activity->properties['permissions_added']);
        $this->assertSame([], $activity->properties['permissions_removed']);
        $this->assertSame(2, $activity->properties['permission_count']);
        $this->assertSame('CreateRole', $activity->properties['source']);
    }

    // ── 2. Rename records previous/new values ────────────────────────────

    public function test_role_rename_records_previous_and_new_name(): void
    {
        $role = Role::create(['name' => 'old-name', 'guard_name' => 'web']);
        $this->actingAs($this->managerWithAssign);

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['name' => 'new-name', 'guard_name' => 'web'])
            ->call('save')
            ->assertHasNoFormErrors();

        $activity = $this->roleActivities('updated')->where('subject_id', $role->id)->sole();

        $this->assertSame('old-name', $activity->properties['previous_name']);
        $this->assertSame('new-name', $activity->properties['new_name']);
    }

    // ── 3. Permission addition records only added ────────────────────────

    public function test_permission_addition_records_only_added_permissions(): void
    {
        $role = Role::create(['name' => 'perm-add-role', 'guard_name' => 'web']);
        $role->givePermissionTo('some-permission');

        $this->actingAs($this->managerWithAssign);

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->set('selectedPermissions', ['some-permission', 'another-permission'])
            ->call('save')
            ->assertHasNoFormErrors();

        $activity = $this->roleActivities('updated')->where('subject_id', $role->id)->sole();

        $this->assertSame(['another-permission'], $activity->properties['permissions_added']);
        $this->assertSame([], $activity->properties['permissions_removed']);
    }

    // ── 4. Permission removal records only removed ───────────────────────

    public function test_permission_removal_records_only_removed_permissions(): void
    {
        $role = Role::create(['name' => 'perm-remove-role', 'guard_name' => 'web']);
        $role->givePermissionTo(['some-permission', 'another-permission']);

        $this->actingAs($this->managerWithAssign);

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->set('selectedPermissions', ['some-permission'])
            ->call('save')
            ->assertHasNoFormErrors();

        $activity = $this->roleActivities('updated')->where('subject_id', $role->id)->sole();

        $this->assertSame([], $activity->properties['permissions_added']);
        $this->assertSame(['another-permission'], $activity->properties['permissions_removed']);
    }

    // ── 5. Mixed add/remove produces one event with both diffs ───────────

    public function test_mixed_add_and_remove_produces_one_event_with_both_diffs(): void
    {
        $role = Role::create(['name' => 'mixed-role', 'guard_name' => 'web']);
        $role->givePermissionTo('some-permission');

        $this->actingAs($this->managerWithAssign);

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->set('selectedPermissions', ['another-permission'])
            ->call('save')
            ->assertHasNoFormErrors();

        $events = $this->roleActivities('updated')->where('subject_id', $role->id);
        $this->assertCount(1, $events);

        $activity = $events->first();
        $this->assertSame(['another-permission'], $activity->properties['permissions_added']);
        $this->assertSame(['some-permission'], $activity->properties['permissions_removed']);
    }

    // ── 6. No-op save creates no audit event ──────────────────────────────

    public function test_no_op_save_creates_no_audit_event(): void
    {
        $role = Role::create(['name' => 'untouched-role', 'guard_name' => 'web', 'description' => 'same', 'status' => 'active']);
        $role->givePermissionTo('some-permission');

        $this->actingAs($this->managerWithAssign);

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['name' => 'untouched-role', 'guard_name' => 'web', 'description' => 'same', 'status' => 'active'])
            ->set('selectedPermissions', ['some-permission'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertCount(0, $this->roleActivities('updated')->where('subject_id', $role->id));
    }

    public function test_description_only_change_is_a_real_committed_change_and_is_audited(): void
    {
        $role = Role::create(['name' => 'desc-role', 'guard_name' => 'web', 'description' => 'old']);

        $this->actingAs($this->managerWithAssign);

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['name' => 'desc-role', 'guard_name' => 'web', 'description' => 'new'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertCount(1, $this->roleActivities('updated')->where('subject_id', $role->id));
    }

    // ── 7. Rejected unauthorized mutation creates no event ────────────────

    public function test_unauthorized_permission_change_via_tampered_payload_creates_no_event(): void
    {
        $role = Role::create(['name' => 'guarded-role', 'guard_name' => 'web']);
        $role->givePermissionTo('some-permission');

        $this->actingAs($this->managerWithoutAssign);

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->set('selectedPermissions', ['some-permission', 'another-permission'])
            ->call('save')
            ->assertHasNoFormErrors();

        // Permissions are unchanged (server-side guard already proven in
        // RolePermissionAssignmentTest) and, since nothing else changed
        // either, no audit event should exist for this save.
        $this->assertSame(['some-permission'], $role->fresh()->permissions->pluck('name')->all());
        $this->assertCount(0, $this->roleActivities('updated')->where('subject_id', $role->id));
    }

    // ── 8/9. Canonical-role rejection creates no success event ────────────

    public function test_canonical_role_rename_rejection_creates_no_success_event(): void
    {
        $role = Role::where('name', 'super_admin')->firstOrFail();
        $this->actingAs($this->superAdmin);

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['name' => 'renamed-super-admin', 'guard_name' => 'web'])
            ->call('save');

        $this->assertSame('super_admin', $role->fresh()->name);
        $this->assertCount(0, $this->roleActivities('updated')->where('subject_id', $role->id));
    }

    public function test_canonical_role_deletion_rejection_creates_no_success_event(): void
    {
        $role = Role::where('name', 'super_admin')->firstOrFail();
        $this->actingAs($this->superAdmin);

        Livewire::test(ListRoles::class)
            ->callTableAction('delete', $role);

        $this->assertNotNull(Role::query()->find($role->id));
        $this->assertCount(0, $this->roleActivities('deleted')->where('subject_id', $role->id));
    }

    // ── 10. Safe role deletion is audited once ─────────────────────────────

    public function test_safe_role_deletion_is_audited_once(): void
    {
        $role = Role::create(['name' => 'disposable', 'guard_name' => 'web']);
        $role->givePermissionTo('some-permission');
        $roleId = $role->id;

        $this->actingAs($this->managerWithAssign);

        Livewire::test(ListRoles::class)
            ->callTableAction('delete', $role);

        $this->assertNull(Role::query()->find($roleId));

        $events = $this->roleActivities('deleted')->where('subject_id', $roleId);
        $this->assertCount(1, $events);
        $this->assertSame(1, $events->first()->properties['permission_count']);
    }

    // ── 11. Bulk mutation: one event per role, no duplication ─────────────

    public function test_bulk_deletion_records_one_event_per_role_without_duplication(): void
    {
        $a = Role::create(['name' => 'bulk-a', 'guard_name' => 'web']);
        $b = Role::create(['name' => 'bulk-b', 'guard_name' => 'web']);

        $this->actingAs($this->managerWithAssign);

        Livewire::test(ListRoles::class)
            ->callTableBulkAction('delete', collect([$a, $b]));

        $events = $this->roleActivities('deleted')->whereIn('subject_id', [$a->id, $b->id]);
        $this->assertCount(2, $events);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $events->pluck('subject_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_bulk_deletion_rejected_for_canonical_role_creates_no_events(): void
    {
        $superAdminRole = Role::where('name', 'super_admin')->firstOrFail();
        $other = Role::create(['name' => 'bulk-c', 'guard_name' => 'web']);

        $this->actingAs($this->superAdmin);

        Livewire::test(ListRoles::class)
            ->callTableBulkAction('delete', collect([$superAdminRole, $other]));

        $this->assertNotNull(Role::query()->find($superAdminRole->id));
        $this->assertNotNull(Role::query()->find($other->id));
        $this->assertCount(0, $this->roleActivities('deleted')->whereIn('subject_id', [$superAdminRole->id, $other->id]));
    }

    // ── 12. Role replication is audited ────────────────────────────────────

    public function test_role_replication_is_audited_with_copied_permissions(): void
    {
        $original = Role::create(['name' => 'replicate-source', 'guard_name' => 'web']);
        $original->givePermissionTo(['some-permission', 'another-permission']);

        $this->actingAs($this->managerWithAssign);

        Livewire::test(ListRoles::class)
            ->callTableAction('replicate', $original, data: ['name' => 'replicate-copy']);

        $replica = Role::where('name', 'replicate-copy')->firstOrFail();
        $activity = $this->roleActivities('replicated')->where('subject_id', $replica->id)->sole();

        $this->assertSame($original->id, $activity->properties['source_role_id']);
        $this->assertSame(['another-permission', 'some-permission'], $activity->properties['permissions_added']);
    }

    public function test_role_replication_without_assign_permission_is_audited_with_no_permissions_copied(): void
    {
        $original = Role::create(['name' => 'replicate-source-2', 'guard_name' => 'web']);
        $original->givePermissionTo('some-permission');

        $this->actingAs($this->managerWithoutAssign);

        Livewire::test(ListRoles::class)
            ->callTableAction('replicate', $original, data: ['name' => 'replicate-copy-2']);

        $replica = Role::where('name', 'replicate-copy-2')->firstOrFail();
        $this->assertSame([], $replica->permissions->pluck('name')->all());

        $activity = $this->roleActivities('replicated')->where('subject_id', $replica->id)->sole();
        $this->assertSame([], $activity->properties['permissions_added']);
    }

    // ── 14. Direct governed service invocation always audits ──────────────

    public function test_direct_recorder_invocation_always_produces_exactly_one_event(): void
    {
        $role = Role::create(['name' => 'direct-service-role', 'guard_name' => 'web']);

        app(RoleAuditRecorder::class)->recordCreated($this->superAdmin, $role, ['some-permission'], 'DirectServiceTest');

        $this->assertCount(1, $this->roleActivities('created')->where('subject_id', $role->id));
    }

    // ── 15. Sensitive/unrelated form data never appears in properties ─────

    public function test_activity_properties_never_contain_raw_form_payload_or_unrelated_data(): void
    {
        $role = Role::create(['name' => 'sensitive-check-role', 'guard_name' => 'web']);
        $this->actingAs($this->managerWithAssign);

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['name' => 'sensitive-check-role-renamed', 'guard_name' => 'web'])
            ->call('save')
            ->assertHasNoFormErrors();

        $activity = $this->roleActivities('updated')->where('subject_id', $role->id)->sole();

        // AuditTrailService::logUser() merges in standard request-context
        // keys (ip_address/user_agent/route/method/session_id) for every
        // audit call app-wide — an established convention, not something
        // this phase introduces or is meant to change. What must never
        // appear is raw form payload, actual session VALUES/contents, or
        // unrelated user/profile data.
        $allowedKeys = [
            'role_id', 'role_name', 'permissions_added', 'permissions_removed',
            'permission_count', 'source', 'previous_name', 'new_name',
            'ip_address', 'user_agent', 'route', 'method', 'session_id',
        ];
        $this->assertEmpty(array_diff(array_keys($activity->properties->toArray()), $allowedKeys));
        $this->assertArrayNotHasKey('password', $activity->properties->toArray());
        $this->assertArrayNotHasKey('guard_name', $activity->properties->toArray());
    }

    // ── 16. Audit viewer can load/filter the resulting event ──────────────

    public function test_activity_log_resource_can_load_a_role_event(): void
    {
        $this->actingAs($this->managerWithAssign);

        Livewire::test(CreateRole::class)
            ->fillForm(['name' => 'viewer-check-role-2', 'guard_name' => 'web'])
            ->call('create')
            ->assertHasNoFormErrors();

        $activity = $this->roleActivities('created')->where('subject_id', Role::where('name', 'viewer-check-role-2')->value('id'))->sole();

        $this->actingAs($this->superAdmin)
            ->get(route('filament.admin.resources.activity-logs.view', $activity))
            ->assertOk()
            ->assertSee('viewer-check-role-2');
    }
}
