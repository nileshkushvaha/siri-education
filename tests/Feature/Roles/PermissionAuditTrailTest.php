<?php

declare(strict_types=1);

namespace Tests\Feature\Roles;

use App\Filament\Resources\Permissions\Pages\CreatePermission;
use App\Filament\Resources\Permissions\Pages\EditPermission;
use App\Filament\Resources\Permissions\Pages\ListPermissions;
use App\Models\Activity;
use App\Models\User;
use App\Services\Admin\PermissionAuditRecorder;
use Database\Seeders\QueueMonitorPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Permission-side counterpart
 * of RoleAuditTrailTest. Every Permission CRUD surface (CreatePermission,
 * EditPermission header delete, PermissionsTable bulk delete) now records
 * through PermissionAuditRecorder -> AuditTrailService instead of having
 * no audit trail at all.
 */
class PermissionAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $managerWithAssign;

    private User $managerWithoutPermission;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ViewAny:Permission', 'View:Permission', 'Create:Permission', 'Update:Permission', 'Delete:Permission', 'DeleteAny:Permission'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $managerRole = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        // InstructorOnboardingResource::getNavigationBadge() scopes
        // User::role('instructor') on every admin page load.
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $this->superAdmin->assignRole($superAdminRole);

        $this->managerWithAssign = User::factory()->create(['status' => 'active']);
        $this->managerWithAssign->assignRole($managerRole);
        $this->managerWithAssign->givePermissionTo(['ViewAny:Permission', 'View:Permission', 'Create:Permission', 'Update:Permission', 'Delete:Permission', 'DeleteAny:Permission']);

        $this->managerWithoutPermission = User::factory()->create(['status' => 'active']);
        $this->managerWithoutPermission->assignRole($managerRole);
    }

    private function permissionActivities(?string $event = null): Collection
    {
        return Activity::query()
            ->where('log_name', 'permissions')
            ->when($event, fn ($q) => $q->where('event', $event))
            ->get();
    }

    // ── 1. Permission creation writes one audit event ──────────────────────

    public function test_permission_creation_writes_one_audit_event(): void
    {
        $this->actingAs($this->managerWithAssign);

        Livewire::test(CreatePermission::class)
            ->fillForm(['name' => 'brand-new-permission', 'guard_name' => 'web'])
            ->call('create')
            ->assertHasNoFormErrors();

        $permission = Permission::where('name', 'brand-new-permission')->firstOrFail();
        $events = $this->permissionActivities('created')->where('subject_id', $permission->id);

        $this->assertCount(1, $events);
        $activity = $events->first();
        $this->assertSame($this->managerWithAssign->id, $activity->causer_id);
        $this->assertSame('brand-new-permission', $activity->properties['permission_name']);
        $this->assertSame('web', $activity->properties['guard_name']);
        $this->assertSame('CreatePermission', $activity->properties['source']);
    }

    // ── 2. Created permission is still auto-granted as before ──────────────

    public function test_created_permission_is_still_auto_granted_to_super_admin(): void
    {
        $this->actingAs($this->managerWithAssign);

        Livewire::test(CreatePermission::class)
            ->fillForm(['name' => 'auto-grant-check', 'guard_name' => 'web'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue($this->superAdmin->fresh()->hasPermissionTo('auto-grant-check'));

        $activity = $this->permissionActivities('created')
            ->where('subject_id', Permission::where('name', 'auto-grant-check')->value('id'))
            ->sole();

        $this->assertTrue($activity->properties['auto_granted_to_super_admin']);
        // One event, not two — the auto-grant is a property, not a
        // separate misleading second event for the same operation.
        $this->assertCount(1, $this->permissionActivities()->where('subject_id', Permission::where('name', 'auto-grant-check')->value('id')));
    }

    // ── 3/4. Seeder-created permission produces no interactive audit event ──

    public function test_seeder_created_permission_produces_no_interactive_audit_event(): void
    {
        // This test's own setUp() already granted permissions to real
        // users, which warms Spatie's permission cache — a fresh deploy
        // would seed with a cold cache, so reset it here to match that
        // real-world starting condition (otherwise the seeder's own
        // Permission::firstOrCreate() + immediate givePermissionTo() can
        // race against a cache populated before the new rows existed).
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seed(QueueMonitorPermissionSeeder::class);

        $this->assertSame(0, $this->permissionActivities('created')->count());
    }

    public function test_seeder_rerun_no_op_produces_no_event(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seed(QueueMonitorPermissionSeeder::class);
        $this->seed(QueueMonitorPermissionSeeder::class);
        $this->seed(QueueMonitorPermissionSeeder::class);

        $this->assertSame(0, $this->permissionActivities()->count());
    }

    // ── 5. Rename records previous/new names ────────────────────────────────

    public function test_permission_rename_records_previous_and_new_name(): void
    {
        $permission = Permission::create(['name' => 'old-perm-name', 'guard_name' => 'web']);
        $this->actingAs($this->managerWithAssign);

        Livewire::test(EditPermission::class, ['record' => $permission->getRouteKey()])
            ->fillForm(['name' => 'new-perm-name', 'guard_name' => 'web'])
            ->call('save')
            ->assertHasNoFormErrors();

        $activity = $this->permissionActivities('updated')->where('subject_id', $permission->id)->sole();

        $this->assertSame('old-perm-name', $activity->properties['previous_name']);
        $this->assertSame('new-perm-name', $activity->properties['new_name']);
    }

    // ── 6. Rename preserves role assignments ────────────────────────────────

    public function test_rename_preserves_existing_role_assignments(): void
    {
        $permission = Permission::create(['name' => 'assigned-perm', 'guard_name' => 'web']);
        $target = Role::create(['name' => 'target-role', 'guard_name' => 'web']);
        $target->givePermissionTo($permission);

        $this->actingAs($this->managerWithAssign);

        Livewire::test(EditPermission::class, ['record' => $permission->getRouteKey()])
            ->fillForm(['name' => 'renamed-assigned-perm', 'guard_name' => 'web'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($target->fresh()->hasPermissionTo('renamed-assigned-perm'));
    }

    // ── 7. No-op edit produces no event ─────────────────────────────────────

    public function test_no_op_edit_produces_no_event(): void
    {
        $permission = Permission::create(['name' => 'untouched-perm', 'guard_name' => 'web']);
        $this->actingAs($this->managerWithAssign);

        Livewire::test(EditPermission::class, ['record' => $permission->getRouteKey()])
            ->fillForm(['name' => 'untouched-perm', 'guard_name' => 'web'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0, $this->permissionActivities('updated')->where('subject_id', $permission->id)->count());
    }

    // ── 8. Validation failure produces no event ─────────────────────────────

    public function test_validation_failure_produces_no_event(): void
    {
        Permission::create(['name' => 'already-exists', 'guard_name' => 'web']);
        $this->actingAs($this->managerWithAssign);

        Livewire::test(CreatePermission::class)
            ->fillForm(['name' => 'already-exists', 'guard_name' => 'web'])
            ->call('create')
            ->assertHasFormErrors(['name']);

        $this->assertSame(0, $this->permissionActivities('created')->count());
    }

    // ── 9. Unauthorized mutation produces no event ──────────────────────────

    public function test_unauthorized_user_cannot_reach_create_page(): void
    {
        $this->actingAs($this->managerWithoutPermission)
            ->get(route('filament.admin.resources.permissions.create'))
            ->assertForbidden();

        $this->assertSame(0, $this->permissionActivities()->count());
    }

    public function test_unauthorized_user_cannot_reach_edit_page(): void
    {
        $permission = Permission::create(['name' => 'guarded-perm', 'guard_name' => 'web']);

        $this->actingAs($this->managerWithoutPermission)
            ->get(route('filament.admin.resources.permissions.edit', $permission))
            ->assertForbidden();

        $this->assertSame(0, $this->permissionActivities()->count());
    }

    public function test_user_without_delete_permission_cannot_delete_and_creates_no_event(): void
    {
        $permission = Permission::create(['name' => 'delete-guarded-perm', 'guard_name' => 'web']);

        $viewOnlyManager = User::factory()->create(['status' => 'active']);
        $viewOnlyManager->assignRole('manager');
        $viewOnlyManager->givePermissionTo(['ViewAny:Permission', 'View:Permission', 'Update:Permission']);

        $this->actingAs($viewOnlyManager);

        Livewire::test(EditPermission::class, ['record' => $permission->getRouteKey()])
            ->assertActionHidden('delete');

        $this->assertNotNull(Permission::find($permission->id));
        $this->assertSame(0, $this->permissionActivities('deleted')->where('subject_id', $permission->id)->count());
    }

    // ── 10. Header deletion writes one event ────────────────────────────────

    public function test_header_deletion_writes_one_event(): void
    {
        $permission = Permission::create(['name' => 'header-delete-perm', 'guard_name' => 'web']);
        $permissionId = $permission->id;

        $this->actingAs($this->managerWithAssign);

        Livewire::test(EditPermission::class, ['record' => $permission->getRouteKey()])
            ->callAction('delete');

        $this->assertNull(Permission::find($permissionId));
        $this->assertCount(1, $this->permissionActivities('deleted')->where('subject_id', $permissionId));
    }

    // ── 11. Row deletion — N/A: PermissionsTable has no row-level delete ───
    // Only EditPermission's header delete and the table's bulk delete exist
    // (confirmed via source inspection).

    // ── 12/13. Bulk deletion audits every committed deletion, no duplicates ─

    public function test_bulk_deletion_audits_every_committed_deletion_without_duplication(): void
    {
        $a = Permission::create(['name' => 'bulk-perm-a', 'guard_name' => 'web']);
        $b = Permission::create(['name' => 'bulk-perm-b', 'guard_name' => 'web']);

        $this->actingAs($this->managerWithAssign);

        Livewire::test(ListPermissions::class)
            ->callTableBulkAction('delete', collect([$a, $b]));

        $this->assertNull(Permission::find($a->id));
        $this->assertNull(Permission::find($b->id));

        $events = $this->permissionActivities('deleted')->whereIn('subject_id', [$a->id, $b->id]);
        $this->assertCount(2, $events);
    }

    /**
     * PermissionsTable's bulk delete wraps the whole batch in ONE
     * DB::transaction() (see app/Filament/Resources/Permissions/Tables/
     * PermissionsTable.php) — if any deletion in the batch throws,
     * everything rolls back, including audit rows already written earlier
     * in that same batch. Exercises that exact mechanism directly (not
     * through the Filament UI, which has no built-in way to force a
     * mid-batch failure) to prove no partial-success audit trail survives.
     */
    public function test_failed_bulk_transaction_leaves_no_partial_success_audit_records(): void
    {
        $a = Permission::create(['name' => 'txn-perm-a', 'guard_name' => 'web']);
        $b = Permission::create(['name' => 'txn-perm-b', 'guard_name' => 'web']);
        $recorder = app(PermissionAuditRecorder::class);

        try {
            DB::transaction(function () use ($a, $b, $recorder): void {
                $recorder->recordDeleted($this->managerWithAssign, $a, [], 'PermissionsTable.bulkDelete');
                $a->delete();

                $recorder->recordDeleted($this->managerWithAssign, $b, [], 'PermissionsTable.bulkDelete');
                throw new \RuntimeException('Simulated mid-batch failure.');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNotNull(Permission::find($a->id), 'The whole transaction must roll back, including the first deletion.');
        $this->assertNotNull(Permission::find($b->id));
        $this->assertSame(0, $this->permissionActivities('deleted')->whereIn('subject_id', [$a->id, $b->id])->count());
    }

    // ── 14. Deletion audit safely records affected-role context ────────────

    public function test_deletion_audit_records_affected_role_context(): void
    {
        // Permission::create() also triggers the existing
        // registerPermissionObserver() auto-grant to super_admin (see
        // test_permission_created_observer_auto_grant_is_not_independently_audited
        // in RoleAuditArchitectureTest) — so super_admin is a real,
        // correctly-affected role here too, not just roleA/roleB.
        $permission = Permission::create(['name' => 'affected-role-perm', 'guard_name' => 'web']);
        $roleA = Role::create(['name' => 'affected-role-a', 'guard_name' => 'web']);
        $roleB = Role::create(['name' => 'affected-role-b', 'guard_name' => 'web']);
        $roleA->givePermissionTo($permission);
        $roleB->givePermissionTo($permission);

        $this->actingAs($this->managerWithAssign);

        Livewire::test(EditPermission::class, ['record' => $permission->getRouteKey()])
            ->callAction('delete');

        $activity = $this->permissionActivities('deleted')->where('subject_id', $permission->id)->sole();

        $this->assertSame(3, $activity->properties['affected_role_count']);
        $this->assertSame(['affected-role-a', 'affected-role-b', 'super_admin'], $activity->properties['affected_role_names']);
    }

    // ── 15. Raw form/session/personal data absent ───────────────────────────

    public function test_activity_properties_never_contain_raw_form_or_session_or_personal_data(): void
    {
        $permission = Permission::create(['name' => 'sensitive-perm-check', 'guard_name' => 'web']);
        $this->actingAs($this->managerWithAssign);

        Livewire::test(EditPermission::class, ['record' => $permission->getRouteKey()])
            ->fillForm(['name' => 'sensitive-perm-check-renamed', 'guard_name' => 'web'])
            ->call('save')
            ->assertHasNoFormErrors();

        $activity = $this->permissionActivities('updated')->where('subject_id', $permission->id)->sole();

        $allowedKeys = [
            'permission_id', 'permission_name', 'guard_name', 'source',
            'previous_name', 'new_name', 'previous_guard_name', 'new_guard_name',
            'ip_address', 'user_agent', 'route', 'method', 'session_id',
        ];
        $this->assertEmpty(array_diff(array_keys($activity->properties->toArray()), $allowedKeys));
        $this->assertArrayNotHasKey('password', $activity->properties->toArray());
    }

    // ── 16. Audit viewer renders and filters the event ──────────────────────

    public function test_activity_log_resource_can_load_a_permission_event(): void
    {
        $this->actingAs($this->managerWithAssign);

        Livewire::test(CreatePermission::class)
            ->fillForm(['name' => 'viewer-check-permission', 'guard_name' => 'web'])
            ->call('create')
            ->assertHasNoFormErrors();

        $activity = $this->permissionActivities('created')
            ->where('subject_id', Permission::where('name', 'viewer-check-permission')->value('id'))
            ->sole();

        $this->actingAs($this->superAdmin)
            ->get(route('filament.admin.resources.activity-logs.view', $activity))
            ->assertOk()
            ->assertSee('viewer-check-permission');
    }

    // ── 17. Direct governed mutation service always audits ─────────────────

    public function test_direct_recorder_invocation_always_produces_exactly_one_event(): void
    {
        $permission = Permission::create(['name' => 'direct-service-permission', 'guard_name' => 'web']);

        app(PermissionAuditRecorder::class)->recordCreated($this->superAdmin, $permission, false, 'DirectServiceTest');

        $this->assertCount(1, $this->permissionActivities('created')->where('subject_id', $permission->id));
    }
}
