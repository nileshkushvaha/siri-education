<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class WhatsAppSettingsPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const PERMISSIONS = [
        'settings.whatsapp.view',
        'settings.whatsapp.update',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Must run before givePermissionTo() below, not just after: if
        // Spatie's permission cache was already warm (e.g. an earlier
        // request in this deploy already resolved permissions), the
        // freshly-created rows above are invisible to it until cleared,
        // and givePermissionTo() throws PermissionDoesNotExist.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])
            ->givePermissionTo(self::PERMISSIONS);
    }
}
