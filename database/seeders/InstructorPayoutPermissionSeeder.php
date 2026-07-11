<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 15 payout-method / withdrawal permissions (Filament Shield
 * naming). Idempotent — required after deploy: policies fall back to
 * "deny" for permissions that do not exist, so without this only
 * super_admin can reach the payout admin. Instructor self-service is
 * ownership-scoped in the policies and needs no permission here. No
 * payout-execution (mark-paid) permission exists in this phase.
 */
class InstructorPayoutPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array MANAGER_PERMISSIONS = [
        'ViewAny:InstructorPayoutMethod', 'View:InstructorPayoutMethod',
        // Managers verify bank details, which requires decrypting them;
        // every access is audit-logged.
        'ViewSensitive:InstructorPayoutMethod',
        'Verify:InstructorPayoutMethod', 'Reject:InstructorPayoutMethod',
        'Disable:InstructorPayoutMethod',
        'ViewAny:InstructorWithdrawalRequest', 'View:InstructorWithdrawalRequest',
        'StartReview:InstructorWithdrawalRequest', 'Approve:InstructorWithdrawalRequest',
        'Reject:InstructorWithdrawalRequest', 'Cancel:InstructorWithdrawalRequest',
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
