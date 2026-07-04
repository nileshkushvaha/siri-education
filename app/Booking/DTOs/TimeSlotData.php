<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use Carbon\CarbonImmutable;

final readonly class TimeSlotData
{
    /** @param ?int $remainingCapacity null = uncapped (e.g. webinars) */
    public function __construct(
        public int $hostId,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public ?int $remainingCapacity = null,
    ) {}
}
