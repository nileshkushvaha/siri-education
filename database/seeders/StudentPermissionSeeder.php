<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class StudentPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = collect([
            'ViewAny:StudentLearningGoal',
            'View:StudentLearningGoal',
            'Create:StudentLearningGoal',
            'Update:StudentLearningGoal',
            'Delete:StudentLearningGoal',
            'Restore:StudentLearningGoal',
            'ForceDelete:StudentLearningGoal',
            'View:StudentFavoriteInstructor',
            'Create:StudentFavoriteInstructor',
            'Delete:StudentFavoriteInstructor',
            'ViewAny:StudentLearningPlan',
            'View:StudentLearningPlan',
            'Create:StudentLearningPlan',
            'Update:StudentLearningPlan',
            'Delete:StudentLearningPlan',
            'View:LearningPlanAssessment',
            'Create:LearningPlanAssessment',
            'View:LearningPlanMilestone',
            'Create:LearningPlanMilestone',
            'Update:LearningPlanMilestone',
            'View:LearningPlanReview',
            'Create:LearningPlanReview',
        ])->map(fn (string $name) => Permission::firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ]));

        Role::whereIn('name', ['manager', 'super_admin'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));
    }
}
