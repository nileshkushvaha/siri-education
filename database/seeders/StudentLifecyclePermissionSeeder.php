<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Student\StudentLifecycleService;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Phase 24H — GAP-013/SRS-2-20/SRS-B1-12. Mirrors InstructorPermissionSeeder's
 * exact pattern: dedicated lifecycle permissions, granted to manager and
 * super_admin only. Super Admin also has blanket access via Gate::before(),
 * independent of these rows.
 */
class StudentLifecyclePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = collect([
            StudentLifecycleService::ACTIVATE_PERMISSION,
            StudentLifecycleService::SUSPEND_PERMISSION,
            StudentLifecycleService::REACTIVATE_PERMISSION,
            StudentLifecycleService::ARCHIVE_PERMISSION,
        ])->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));

        Role::whereIn('name', ['manager', 'super_admin'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));
    }
}
