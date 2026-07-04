<?php

declare(strict_types=1);

namespace App\Booking\Assignment\Scorers;

use App\Booking\Contracts\TeacherScorerInterface;
use App\Booking\DTOs\AssignmentCriteriaData;
use App\Models\User;

/** Admin-set boost: user_profiles.assignment_priority (0–100). */
final class PriorityScorer implements TeacherScorerInterface
{
    private const int DEFAULT_PRIORITY = 50;

    public function score(User $teacher, AssignmentCriteriaData $criteria): float
    {
        return ($teacher->profile?->assignment_priority ?? self::DEFAULT_PRIORITY) / 100;
    }

    public function weight(): float
    {
        return 1.0;
    }
}
