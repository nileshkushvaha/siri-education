<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StudentLearningPlan;
use App\Models\User;

class StudentLearningPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:StudentLearningPlan');
    }

    public function view(User $user, StudentLearningPlan $plan): bool
    {
        return $plan->student_user_id === $user->id
            || $plan->primary_instructor_user_id === $user->id
            || $user->can('View:StudentLearningPlan');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('student') || $user->can('Create:StudentLearningPlan');
    }

    public function update(User $user, StudentLearningPlan $plan): bool
    {
        return $plan->primary_instructor_user_id === $user->id
            || $user->can('Update:StudentLearningPlan');
    }

    public function delete(User $user, StudentLearningPlan $plan): bool
    {
        return $user->can('Delete:StudentLearningPlan');
    }
}
