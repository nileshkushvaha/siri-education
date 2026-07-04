<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\AssignmentCriteriaData;
use App\Models\User;

/**
 * One scoring dimension for BestScoreStrategy. Implementations are
 * tagged 'booking.assignment_scorers' in BookingServiceProvider —
 * adding a scorer extends the engine without touching the strategy.
 */
interface TeacherScorerInterface
{
    /** Normalized score in [0, 1]. */
    public function score(User $teacher, AssignmentCriteriaData $criteria): float;

    /** Relative importance of this dimension. */
    public function weight(): float;
}
