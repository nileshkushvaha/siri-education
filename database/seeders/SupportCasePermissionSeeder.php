<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Support case permissions (GAP-016 / SRS Chapter 25, Filament Shield
 * naming). Idempotent. Every write goes through SupportCaseService —
 * these permissions gate which actions a manager may invoke, never a
 * direct model write.
 */
class SupportCasePermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array MANAGER_PERMISSIONS = [
        'ViewAny:SupportCase', 'View:SupportCase', 'Create:SupportCase',
        'Assign:SupportCase', 'Reply:SupportCase', 'AddInternalNote:SupportCase',
        'Escalate:SupportCase', 'Resolve:SupportCase', 'Close:SupportCase', 'Reopen:SupportCase',
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
