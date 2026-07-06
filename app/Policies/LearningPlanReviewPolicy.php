<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LearningPlanReview;
use App\Models\User;

class LearningPlanReviewPolicy
{
    public function view(User $user, LearningPlanReview $review): bool
    {
        $plan = $review->learningPlan;

        return $plan->student_user_id === $user->id
            || $plan->primary_instructor_user_id === $user->id
            || $user->can('View:LearningPlanReview');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('instructor') || $user->can('Create:LearningPlanReview');
    }
}
