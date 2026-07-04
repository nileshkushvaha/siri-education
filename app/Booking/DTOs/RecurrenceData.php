<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

/** Weekly recurrence: N occurrences spaced by whole weeks. */
final readonly class RecurrenceData
{
    public const int MAX_OCCURRENCES = 12;

    public function __construct(
        public int $occurrences,
        public int $intervalWeeks = 1,
    ) {}
}
