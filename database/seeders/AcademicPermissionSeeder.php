<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AcademicPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    private const MODULES = [
        'AcademicCategory',
        'Subject',
        'SubjectTopic',
        'AcademicLevel',
        'InstructorSubjectTopic',
        'Curriculum',
        'CurriculumVersion',
        'CurriculumModule',
        'EducationSystem',
        'InstructorCurriculumEligibility',
    ];

    private const MANAGER_ACTIONS = [
        'ViewAny',
        'View',
        'Create',
        'Update',
        'Delete',
        'DeleteAny',
        'Restore',
        'RestoreAny',
        'Replicate',
        'Reorder',
    ];

    private const SUPER_ONLY_ACTIONS = [
        'ForceDelete',
        'ForceDeleteAny',
    ];

    public function run(): void
    {
        $managerPermissions = [];

        foreach (self::MODULES as $module) {
            foreach (self::MANAGER_ACTIONS as $action) {
                $managerPermissions[] = "{$action}:{$module}";
            }

            foreach (self::SUPER_ONLY_ACTIONS as $action) {
                Permission::firstOrCreate(['name' => "{$action}:{$module}", 'guard_name' => 'web']);
            }
        }

        foreach ($managerPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])
            ->givePermissionTo($managerPermissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
