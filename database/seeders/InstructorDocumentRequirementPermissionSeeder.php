<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * No Delete/DeleteAny/ForceDelete permissions exist for this module —
 * InstructorDocumentRequirement can never be deleted (PreventsHardDeletion),
 * only deactivated via its own `active` flag, so there is nothing for a
 * delete-family permission to gate.
 */
class InstructorDocumentRequirementPermissionSeeder extends Seeder
{
    private const array ACTIONS = ['ViewAny', 'View', 'Create', 'Update'];

    public function run(): void
    {
        $permissions = collect(self::ACTIONS)
            ->map(fn (string $action): Permission => Permission::firstOrCreate([
                'name' => "{$action}:InstructorDocumentRequirement",
                'guard_name' => 'web',
            ]));

        Role::whereIn('name', ['manager', 'super_admin'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
