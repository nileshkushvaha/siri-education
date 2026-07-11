<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 14.2 compensation-agreement permissions (Filament Shield
 * naming). Idempotent — required after deploy: policies fall back to
 * "deny" for permissions that do not exist, so without this only
 * super_admin can manage compensation. Instructors never receive
 * mutation permissions — their own-agreement visibility is
 * ownership-scoped in the policy.
 */
class InstructorCompensationPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array MANAGER_PERMISSIONS = [
        'ViewAny:InstructorCompensationAgreement',
        'View:InstructorCompensationAgreement',
        'Create:InstructorCompensationAgreement',
        'Schedule:InstructorCompensationAgreement',
        'Activate:InstructorCompensationAgreement',
        'End:InstructorCompensationAgreement',
        'Cancel:InstructorCompensationAgreement',
        'ViewHistory:InstructorCompensationAgreement',
        'Configure:InstructorCompensationAgreement',
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
