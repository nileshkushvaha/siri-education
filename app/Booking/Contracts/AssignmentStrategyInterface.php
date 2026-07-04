<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\AssignmentCriteriaData;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Picks one teacher from pre-filtered, bookable candidates.
 * Register implementations in BookingServiceProvider; select via
 * BookingSettings::assignment_strategy — no core changes needed for
 * new algorithms.
 */
interface AssignmentStrategyInterface
{
    /** Stable identifier stored in settings (snake_case). */
    public function key(): string;

    /** @param Collection<int, User> $candidates */
    public function assign(AssignmentCriteriaData $criteria, Collection $candidates): ?User;
}
