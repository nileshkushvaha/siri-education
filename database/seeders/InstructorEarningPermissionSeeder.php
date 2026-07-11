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
        'ViewAny:InstructorEarning', 'View:InstructorEarning',
        'Release:InstructorEarning', 'Reverse:InstructorEarning',
        'ViewAny:InstructorSettlementBatch', 'View:InstructorSettlementBatch',
        'Create:InstructorSettlementBatch',
        'Approve:InstructorSettlementBatch', 'MarkPaid:InstructorSettlementBatch',
        'Cancel:InstructorSettlementBatch',
    ];

    /**
     * Phase 14.5 cleanup: earnings and settlement batches are immutable
     * financial records — no workflow edits or deletes them, so the
     * Update/Delete permissions were removed entirely (policies return
     * false for everyone; lifecycle mutations use the precise Release /
     * Reverse / Approve / MarkPaid / Cancel permissions above).
     */
    private const array REMOVED_PERMISSIONS = [
        'Update:InstructorEarning',
        'Delete:InstructorEarning',
        'Update:InstructorSettlementBatch',
        'Delete:InstructorSettlementBatch',
    ];

    public function run(): void
    {
        foreach (self::MANAGER_PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Permission::query()->whereIn('name', self::REMOVED_PERMISSIONS)->delete();

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])
            ->givePermissionTo(self::MANAGER_PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
