<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Payout-execution permissions (Filament Shield naming). Idempotent —
 * required after deploy: policies fall back to "deny" for
 * permissions that do not exist, so without this only super_admin can
 * queue, retry, cancel, or reconcile a payout. No manual mark-paid
 * permission exists, and none ever should — only a provider-confirmed
 * outcome, applied by InstructorPayoutExecutionService, can mark a
 * withdrawal paid. Instructors receive none of these permissions
 * (invariant #7 — they cannot execute or retry their own payout).
 */
class InstructorPayoutExecutionPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array MANAGER_PERMISSIONS = [
        'ViewAny:InstructorPayoutAttempt', 'View:InstructorPayoutAttempt',
        'Execute:InstructorPayoutAttempt', 'Retry:InstructorPayoutAttempt',
        'Cancel:InstructorPayoutAttempt', 'Reconcile:InstructorPayoutAttempt',
        'ViewAny:InstructorPayoutReconciliationIssue', 'View:InstructorPayoutReconciliationIssue',
        'Assign:InstructorPayoutReconciliationIssue', 'Resolve:InstructorPayoutReconciliationIssue',
        'Configure:InstructorPayoutExecution',
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
