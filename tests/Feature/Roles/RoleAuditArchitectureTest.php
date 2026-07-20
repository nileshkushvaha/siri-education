<?php

declare(strict_types=1);

namespace Tests\Feature\Roles;

use App\Models\Activity;
use Database\Seeders\QueueMonitorPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 24Q/24Q.1 — GAP-011/SRS-23-6: architecture guard covering BOTH the
 * Role and Permission domains. Every governed mutation surface must call
 * AuditTrailService (via RoleAuditRecorder or PermissionAuditRecorder,
 * each of which only ever calls AuditTrailService::logUser()) — never raw
 * activity(). Scoped narrowly to the files this phase governs, so
 * unrelated legacy activity() usage elsewhere in the app is never flagged.
 *
 * Read-only pages (ListRoles/ListPermissions — no mutation logic of their
 * own) and the bootstrap-only seeder/observer path are explicitly
 * classified rather than silently ignored, per Phase 24Q.1 Step 6.
 */
class RoleAuditArchitectureTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> Role-domain files that must route through RoleAuditRecorder. */
    public static function roleGovernedFiles(): array
    {
        $base = base_path();

        return [
            $base.'/app/Filament/Resources/Roles/Pages/CreateRole.php',
            $base.'/app/Filament/Resources/Roles/Pages/EditRole.php',
            $base.'/app/Filament/Resources/Roles/Tables/RolesTable.php',
        ];
    }

    /** @return list<string> Permission-domain files that must route through PermissionAuditRecorder. */
    public static function permissionGovernedFiles(): array
    {
        $base = base_path();

        return [
            $base.'/app/Filament/Resources/Permissions/Pages/CreatePermission.php',
            $base.'/app/Filament/Resources/Permissions/Pages/EditPermission.php',
            $base.'/app/Filament/Resources/Permissions/Tables/PermissionsTable.php',
        ];
    }

    /** @return list<string> Read-only pages — no mutation logic, correctly exempt from recorder usage. */
    public static function readOnlyPages(): array
    {
        $base = base_path();

        return [
            $base.'/app/Filament/Resources/Roles/Pages/ListRoles.php',
            $base.'/app/Filament/Resources/Roles/Pages/ViewRole.php',
            $base.'/app/Filament/Resources/Permissions/Pages/ListPermissions.php',
        ];
    }

    public function test_governed_role_and_permission_surfaces_never_call_raw_activity_directly(): void
    {
        foreach ([...self::roleGovernedFiles(), ...self::permissionGovernedFiles(), base_path('app/Services/Admin/RoleAuditRecorder.php'), base_path('app/Services/Admin/PermissionAuditRecorder.php')] as $file) {
            $this->assertFileExists($file);
            $source = file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression(
                '/(?<![\w:>])activity\s*\(/',
                $source,
                basename($file).' must route through AuditTrailService (via RoleAuditRecorder/PermissionAuditRecorder), never call the raw activity() helper.'
            );
        }
    }

    public function test_role_surfaces_reference_role_audit_recorder(): void
    {
        foreach (self::roleGovernedFiles() as $file) {
            $source = file_get_contents($file);

            $this->assertTrue(
                str_contains($source, 'RoleAuditRecorder'),
                basename($file).' must use RoleAuditRecorder.'
            );
        }

        $recorderSource = file_get_contents(base_path('app/Services/Admin/RoleAuditRecorder.php'));
        $this->assertTrue(str_contains($recorderSource, 'AuditTrailService'));
        $this->assertSame(
            4,
            substr_count($recorderSource, '$this->audit->logUser('),
            'RoleAuditRecorder must be a thin wrapper around AuditTrailService::logUser() — one call per record*() method, not a second logger.'
        );
    }

    public function test_permission_surfaces_reference_permission_audit_recorder(): void
    {
        foreach (self::permissionGovernedFiles() as $file) {
            $source = file_get_contents($file);

            $this->assertTrue(
                str_contains($source, 'PermissionAuditRecorder'),
                basename($file).' must use PermissionAuditRecorder.'
            );
        }

        $recorderSource = file_get_contents(base_path('app/Services/Admin/PermissionAuditRecorder.php'));
        $this->assertTrue(str_contains($recorderSource, 'AuditTrailService'));
        $this->assertSame(
            3,
            substr_count($recorderSource, '$this->audit->logUser('),
            'PermissionAuditRecorder must be a thin wrapper around AuditTrailService::logUser() — one call per record*() method, not a second logger.'
        );
    }

    /**
     * Future-proofing: any new page added under Roles/Pages or
     * Permissions/Pages must either be explicitly declared read-only
     * above, or reference the relevant recorder — a new mutation surface
     * added without wiring in audit routing fails this test instead of
     * silently going unaudited.
     */
    public function test_no_undeclared_role_or_permission_page_exists_without_audit_routing_or_read_only_declaration(): void
    {
        $governed = [...self::roleGovernedFiles(), ...self::permissionGovernedFiles()];
        $readOnly = self::readOnlyPages();

        $directories = [
            base_path('app/Filament/Resources/Roles/Pages'),
            base_path('app/Filament/Resources/Permissions/Pages'),
        ];

        foreach ($directories as $directory) {
            foreach (glob($directory.'/*.php') as $file) {
                if (in_array($file, $readOnly, true)) {
                    continue;
                }

                $source = file_get_contents($file);
                $isGoverned = in_array($file, $governed, true);
                $referencesRecorder = str_contains($source, 'RoleAuditRecorder') || str_contains($source, 'PermissionAuditRecorder');

                $this->assertTrue(
                    $isGoverned || $referencesRecorder,
                    basename($file).' is a new/undeclared Role or Permission page — either mark it read-only in readOnlyPages() above (if it has no mutation logic) or wire it through the relevant AuditRecorder.'
                );
            }
        }
    }

    // ── Step 6 (24Q) / Step 5 (24Q.1): seeders and the system observer are explicitly classified ──

    public function test_permission_seeder_reruns_produce_no_roles_or_permissions_audit_noise(): void
    {
        $this->seed(QueueMonitorPermissionSeeder::class);
        $this->seed(QueueMonitorPermissionSeeder::class);
        $this->seed(QueueMonitorPermissionSeeder::class);

        $this->assertSame(
            0,
            Activity::query()->whereIn('log_name', ['roles', 'permissions', 'users'])->count(),
            'Seeding (idempotent, unauthenticated, bootstrap) must never write interactive role/permission audit rows.'
        );
    }

    /**
     * AppServiceProvider::registerPermissionObserver() (Permission::created
     * -> auto-grant to super_admin) is deliberately left without its own
     * audit call — it fires on every permission-seeder run project-wide
     * (dozens of *PermissionSeeder classes), so adding one there would
     * violate the seeder-noise exclusion above. This test documents that
     * classification explicitly rather than leaving it silently untested:
     * the observer's side effect is real (confirmed here), and the ONLY
     * place it's paired with an audit event is CreatePermission's own
     * 'created' event (auto_granted_to_super_admin property) — see
     * PermissionAuditTrailTest.
     */
    public function test_permission_created_observer_auto_grant_is_not_independently_audited(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        Permission::create(['name' => 'observer-check-permission', 'guard_name' => 'web']);

        $this->assertTrue(
            Role::where('name', 'super_admin')->first()->hasPermissionTo('observer-check-permission'),
            'The existing auto-grant observer behavior must be preserved.'
        );
        $this->assertSame(
            0,
            Activity::query()->where('log_name', 'permissions')->count(),
            'A raw model create (bypassing CreatePermission entirely) must not be audited — only the governed Filament surface is.'
        );
    }
}
