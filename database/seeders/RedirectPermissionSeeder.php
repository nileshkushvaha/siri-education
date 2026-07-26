<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * GAP-036 redirect-management permissions (Filament Shield naming).
 * No Delete/Restore permission — redirects are deactivated, never hard
 * deleted, per requirement #5's historical-auditability rule.
 */
class RedirectPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array MANAGER_PERMISSIONS = [
        'ViewAny:Redirect',
        'View:Redirect',
        'Create:Redirect',
        'Update:Redirect',
        'Activate:Redirect',
        'Deactivate:Redirect',
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
