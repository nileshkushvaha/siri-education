<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LearningPlanMilestone;
use App\Models\User;

class LearningPlanMilestonePolicy
{
    public function view(User $user, LearningPlanMilestone $milestone): bool
    {
        $plan = $milestone->learningPlan;

        return $plan->student_user_id === $user->id
            || $plan->primary_instructor_user_id === $user->id
            || $user->can('View:LearningPlanMilestone');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('instructor') || $user->can('Create:LearningPlanMilestone');
    }
}
