<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Wallet module permissions (Filament Shield naming). Idempotent.
 *
 * `manager` gets read-only access by default (ViewAny/View) — useful
 * for support without money-moving power. `Manage:Wallet` (freeze/
 * unfreeze/close/admin-adjustment/reversal) is deliberately NOT granted
 * to any role here: a super_admin must consciously grant it to specific
 * managers via Roles & Permissions. super_admin always has it via
 * Gate::before() regardless.
 */
class WalletPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array MANAGER_READ_PERMISSIONS = [
        'ViewAny:Wallet', 'View:Wallet',
        'ViewAny:WalletLedgerEntry', 'View:WalletLedgerEntry',
    ];

    private const array MANAGE_ONLY_PERMISSIONS = [
        'Create:Wallet', 'Manage:Wallet',
    ];

    public function run(): void
    {
        foreach ([...self::MANAGER_READ_PERMISSIONS, ...self::MANAGE_ONLY_PERMISSIONS] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])
            ->givePermissionTo(self::MANAGER_READ_PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
