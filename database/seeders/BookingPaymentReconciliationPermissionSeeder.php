<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Booking payment reconciliation-issue permissions (mirrors
 * InstructorPayoutExecutionPermissionSeeder's reconciliation grants).
 * Idempotent. No Create/Update/Delete — an issue is only ever written
 * by BookingPaymentReconciliationService; these four permissions cover
 * every mutation Filament exposes (assign, investigate, resolve,
 * reconcile-now).
 */
class BookingPaymentReconciliationPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array MANAGER_PERMISSIONS = [
        'ViewAny:BookingPaymentReconciliationIssue', 'View:BookingPaymentReconciliationIssue',
        'Assign:BookingPaymentReconciliationIssue', 'Resolve:BookingPaymentReconciliationIssue',
        'ReconcileNow:BookingPaymentReconciliationIssue',
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
