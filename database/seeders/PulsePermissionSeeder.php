<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 24O — GAP-033: `pulse.view` gates the Laravel Pulse dashboard,
 * mirroring the dedicated-permission-per-system-tool convention already
 * used by Queue Monitor/Scheduler Monitor/Cache Manager. Idempotent;
 * super_admin always bypasses via Gate::before().
 */
class PulsePermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        Permission::firstOrCreate(['name' => 'pulse.view', 'guard_name' => 'web']);

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])
            ->givePermissionTo('pulse.view');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
