<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Compliance-flag review permissions (Filament Shield naming).
 * Idempotent. No Create/Edit/Delete permission exists or ever will —
 * ComplianceMonitoringService is the only writer, and every
 * user-facing action goes through it.
 */
class SuspiciousActivityFlagPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array MANAGER_PERMISSIONS = [
        'ViewAny:SuspiciousActivityFlag',
        'View:SuspiciousActivityFlag',
        'BeginReview:SuspiciousActivityFlag',
        'Resolve:SuspiciousActivityFlag',
        'Dismiss:SuspiciousActivityFlag',
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
