<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 4E.2 — permissions for the generic payment discrepancy queue.
 * Idempotent, and deliberately three permissions only.
 *
 * Manager and super_admin (via the Permission::created observer) are
 * the only holders. Instructors and students get NOTHING: a payment
 * reconciliation issue is internal financial operations, and its
 * evidence concerns money the platform collected, not a lesson either
 * of them participates in.
 *
 * There is deliberately no `Update:Payment`, no `MarkPaid:Payment`, and
 * no create/update/delete on the issue itself. `Resolve` closes the
 * operational record and touches no financial state whatsoever — the
 * absence of a permission that could settle a payment by hand is the
 * point of the whole queue.
 */
class PaymentReconciliationPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array MANAGER_PERMISSIONS = [
        'ViewAny:PaymentReconciliationIssue',
        'View:PaymentReconciliationIssue',
        'Resolve:PaymentReconciliationIssue',
    ];

    public function run(): void
    {
        foreach (self::MANAGER_PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Must run before givePermissionTo(): Spatie caches the
        // permission list, and one created moments ago in this same
        // process is invisible until the cache is dropped.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])
            ->givePermissionTo(self::MANAGER_PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
