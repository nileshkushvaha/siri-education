<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Booking payment record permissions (Filament Shield naming).
 * Idempotent. Still no Manage/Create/Update/Delete permission — a
 * payment attempt is only ever written by the providers and the
 * webhook/checkout-verification flow. Phase 16A.1 adds one mutation:
 * `RefundViaProvider:BookingPayment`, the separately-permissioned
 * exception action for a direct gateway refund (the normal
 * cancellation path is automatic and needs no permission — it credits
 * the wallet, never the gateway). Phase 16C adds
 * `RetryVerification:BookingPayment` — re-runs an authenticated
 * provider status fetch (BookingPaymentReconciliationService::reconcileAttempt())
 * on demand; never a status edit, never a mark-paid action.
 */
class BookingPaymentPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array MANAGER_PERMISSIONS = [
        'ViewAny:BookingPayment', 'View:BookingPayment',
        'RefundViaProvider:BookingPayment', 'RetryVerification:BookingPayment',
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
