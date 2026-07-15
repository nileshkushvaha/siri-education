<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use Carbon\CarbonImmutable;

final readonly class AvailabilityQueryData
{
    public function __construct(
        public int $instructorId,
        public string $typeKey,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $timezone = 'UTC',
    ) {}
}
