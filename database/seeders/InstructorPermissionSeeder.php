<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Instructor\InstructorOnboardingService;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class InstructorPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::firstOrCreate([
            'name' => InstructorOnboardingService::REVIEW_PERMISSION,
            'guard_name' => 'web',
        ]);

        Role::whereIn('name', ['manager', 'super_admin'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));
    }
}
