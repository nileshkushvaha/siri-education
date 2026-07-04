<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use App\Models\Booking;
use Illuminate\Support\Collection;

/**
 * Outcome of a recurring booking request. Occurrences that clash with
 * availability are skipped and reported — the rest are booked.
 */
final readonly class RecurringBookingResult
{
    /**
     * @param  Collection<int, Booking>  $booked
     * @param  array<string, string>  $failures  ISO start => reason
     */
    public function __construct(
        public string $groupId,
        public Collection $booked,
        public array $failures,
    ) {}

    public function allBooked(): bool
    {
        return $this->failures === [];
    }
}
