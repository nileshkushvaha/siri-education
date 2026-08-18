<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * AI platform permissions (Filament Shield naming).
 *
 * Deliberately narrow. There is no Execute/Approve/Apply permission,
 * because no AI output ever applies itself — an AI run only ever
 * produces a suggestion the owning domain's existing permissions
 * already govern. Granting "use AI" as a right would imply otherwise.
 *
 * TestConnection is separate from Configure so an operator can verify
 * credentials without holding the right to change them. No ViewRuns
 * permission is seeded: P0 has no run-listing surface, and seeding a
 * permission for a screen that does not exist would imply one does.
 * Idempotent — required after deploy: page actions fall back to
 * hidden/denied for permissions that do not exist.
 */
class AiPlatformPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array MANAGER_PERMISSIONS = [
        'Configure:AiPlatform',
        'TestConnection:AiPlatform',
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
