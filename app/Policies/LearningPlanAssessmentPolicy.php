<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LearningPlanAssessment;
use App\Models\User;

class LearningPlanAssessmentPolicy
{
    public function view(User $user, LearningPlanAssessment $assessment): bool
    {
        $plan = $assessment->learningPlan;

        return $plan->student_user_id === $user->id
            || $plan->primary_instructor_user_id === $user->id
            || $user->can('View:LearningPlanAssessment');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('instructor') || $user->can('Create:LearningPlanAssessment');
    }
}
