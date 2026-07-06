<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StudentLearningGoal;
use App\Models\User;

class StudentLearningGoalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:StudentLearningGoal');
    }

    public function view(User $user, StudentLearningGoal $goal): bool
    {
        return $goal->user_id === $user->id || $user->can('View:StudentLearningGoal');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('student') || $user->can('Create:StudentLearningGoal');
    }

    public function update(User $user, StudentLearningGoal $goal): bool
    {
        return $goal->user_id === $user->id || $user->can('Update:StudentLearningGoal');
    }

    public function delete(User $user, StudentLearningGoal $goal): bool
    {
        return $goal->user_id === $user->id || $user->can('Delete:StudentLearningGoal');
    }

    public function restore(User $user, StudentLearningGoal $goal): bool
    {
        return $user->can('Restore:StudentLearningGoal');
    }

    public function forceDelete(User $user, StudentLearningGoal $goal): bool
    {
        return $user->can('ForceDelete:StudentLearningGoal');
    }
}
