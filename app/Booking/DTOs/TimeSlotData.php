<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use Carbon\CarbonImmutable;

final readonly class TimeSlotData
{
    public function __construct(
        public int $instructorId,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {}
}
