<?php

declare(strict_types=1);

namespace App\Booking\Assignment\Scorers;

use App\Booking\Contracts\TeacherScorerInterface;
use App\Booking\DTOs\AssignmentCriteriaData;
use App\Models\User;
use Throwable;

/**
 * Prefers teachers whose profile timezone is close to the requester's.
 * Neutral (0.5) when either side has no usable timezone.
 */
final class TimezoneScorer implements TeacherScorerInterface
{
    private const float NEUTRAL = 0.5;

    public function score(User $teacher, AssignmentCriteriaData $criteria): float
    {
        $teacherTz = $teacher->profile?->timezone;

        if ($criteria->timezone === null || $teacherTz === null) {
            return self::NEUTRAL;
        }

        try {
            $reference = $criteria->startsAt;
            $diffHours = abs(
                $reference->setTimezone($teacherTz)->getOffset() - $reference->setTimezone($criteria->timezone)->getOffset()
            ) / 3600;
        } catch (Throwable) {
            return self::NEUTRAL;
        }

        return 1.0 - min($diffHours / 12, 1.0);
    }

    public function weight(): float
    {
        return 0.5;
    }
}
