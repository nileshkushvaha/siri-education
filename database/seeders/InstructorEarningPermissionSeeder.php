<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Instructor earnings/settlement permissions (Filament Shield naming).
 * Idempotent — required after deploy: policies fall back to "deny" for
 * permissions that do not exist, so without this only super_admin can
 * reach the earnings admin.
 */
class InstructorEarningPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array MANAGER_PERMISSIONS = [
        'ViewAny:InstructorEarning', 'View:InstructorEarning', 'Update:InstructorEarning',
        'Release:InstructorEarning', 'Reverse:InstructorEarning',
        'ViewAny:InstructorSettlementBatch', 'View:InstructorSettlementBatch',
        'Create:InstructorSettlementBatch', 'Update:InstructorSettlementBatch',
        'Approve:InstructorSettlementBatch', 'MarkPaid:InstructorSettlementBatch',
        'Cancel:InstructorSettlementBatch',
    ];

    /** Destructive/irreversible — stays super_admin-only. */
    private const array SUPER_ONLY_PERMISSIONS = [
        'Delete:InstructorEarning',
        'Delete:InstructorSettlementBatch',
    ];

    public function run(): void
    {
        foreach ([...self::MANAGER_PERMISSIONS, ...self::SUPER_ONLY_PERMISSIONS] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])
            ->givePermissionTo(self::MANAGER_PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
