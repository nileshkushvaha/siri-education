<?php

declare(strict_types=1);

namespace App\Booking\Assignment\Scorers;

use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\TeacherScorerInterface;
use App\Booking\DTOs\AssignmentCriteriaData;
use App\Models\User;

/** Prefers teachers with fewer upcoming active bookings. */
final class WorkloadScorer implements TeacherScorerInterface
{
    public function __construct(
        private readonly BookingRepositoryInterface $bookings,
    ) {}

    public function score(User $teacher, AssignmentCriteriaData $criteria): float
    {
        return 1.0 / (1 + $this->bookings->activeUpcomingCountForInstructor($teacher->id));
    }

    public function weight(): float
    {
        return 1.0;
    }
}
