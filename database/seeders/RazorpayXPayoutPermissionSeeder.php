<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 16B RazorpayX-specific permissions (Filament Shield naming).
 * Deliberately does NOT include Manage/MarkPaid/Delete/Edit — no manual
 * mark-paid path exists for any provider (see
 * InstructorPayoutExecutionPermissionSeeder). Execution itself stays
 * gated by the existing `Execute:InstructorPayoutAttempt` permission —
 * these permissions cover RazorpayX configuration and destination
 * provisioning only, never payout execution or maker-checker approval.
 * Idempotent — required after deploy: policies fall back to "deny" for
 * permissions that do not exist.
 */
class RazorpayXPayoutPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array MANAGER_PERMISSIONS = [
        'Configure:RazorpayXPayout',
        'TestConnection:RazorpayXPayout',
        'ConfirmIpAllowlisting:RazorpayXPayout',
        'ProvisionDestination:RazorpayXPayout',
        'RefreshDestination:RazorpayXPayout',
        'ViewProviderDetails:RazorpayXPayout',
        'ProcessWebhook:RazorpayXPayout',
        'Reconcile:RazorpayXPayout',
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
