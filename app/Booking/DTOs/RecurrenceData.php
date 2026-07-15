<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use App\Booking\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;

/** N occurrences spaced by whole days (Daily) or whole weeks (Weekly). */
final readonly class RecurrenceData
{
    public const int MAX_OCCURRENCES = 12;

    public function __construct(
        public int $occurrences,
        public RecurrenceFrequency $frequency = RecurrenceFrequency::Weekly,
        public int $interval = 1,
    ) {}

    public function nextStartsAt(CarbonImmutable $first, int $index): CarbonImmutable
    {
        return match ($this->frequency) {
            RecurrenceFrequency::Daily => $first->addDays($index * $this->interval),
            RecurrenceFrequency::Weekly => $first->addWeeks($index * $this->interval),
        };
    }
}
