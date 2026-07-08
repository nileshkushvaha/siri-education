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
 * Idempotent. Read-only module — there is no Manage/Create/Update
 * permission because the admin panel never edits a payment attempt;
 * it is only ever written by RazorpayPaymentProvider and the webhook/
 * checkout-verification flow.
 */
class BookingPaymentPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array MANAGER_READ_PERMISSIONS = [
        'ViewAny:BookingPayment', 'View:BookingPayment',
    ];

    public function run(): void
    {
        foreach (self::MANAGER_READ_PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])
            ->givePermissionTo(self::MANAGER_READ_PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
