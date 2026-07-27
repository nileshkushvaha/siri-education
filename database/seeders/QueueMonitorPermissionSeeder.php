<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * `queue_monitor.retry_failed_jobs` is the
 * dedicated, recovery-action permission — distinct from
 * `queue_monitor.view`, since viewing failed jobs never implies the
 * ability to retry them. `queue_monitor.view` itself was referenced by
 * QueueMonitorPolicy/QueueMonitorPage since the page was built but had
 * no seeder — this closes that pre-existing gap too, since without it
 * no non-super-admin could ever reach the page this phase adds retry
 * to. Idempotent; super_admin always bypasses via Gate::before().
 */
class QueueMonitorPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array MANAGER_PERMISSIONS = [
        'queue_monitor.view',
        'queue_monitor.retry_failed_jobs',
    ];

    public function run(): void
    {
        foreach (self::MANAGER_PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])
            ->givePermissionTo(self::MANAGER_PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
