<?php

declare(strict_types=1);

namespace App\Booking\Assignment\Strategies;

use App\Booking\Contracts\AssignmentStrategyInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\DTOs\AssignmentCriteriaData;
use App\Models\User;
use Illuminate\Support\Collection;

/** Alternative algorithm: fewest upcoming active bookings wins. */
final class LeastLoadedStrategy implements AssignmentStrategyInterface
{
    public const string KEY = 'least_loaded';

    public function __construct(
        private readonly BookingRepositoryInterface $bookings,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function assign(AssignmentCriteriaData $criteria, Collection $candidates): ?User
    {
        return $candidates
            ->sortBy->id
            ->sortBy(fn (User $teacher): int => $this->bookings->activeUpcomingCountForHost($teacher->id))
            ->first();
    }
}
