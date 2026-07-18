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
        $permissions = collect([
            InstructorOnboardingService::REVIEW_PERMISSION,
            InstructorOnboardingService::ACTIVATE_PERMISSION,
            InstructorOnboardingService::VACATION_PERMISSION,
            InstructorOnboardingService::SUSPEND_PERMISSION,
            InstructorOnboardingService::ARCHIVE_PERMISSION,
            InstructorOnboardingService::INTERVIEW_PERMISSION,
        ])->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));

        Role::whereIn('name', ['manager', 'super_admin'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));
    }
}
