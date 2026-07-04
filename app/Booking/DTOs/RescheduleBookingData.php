<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use App\Booking\Enums\BookingActor;
use Carbon\CarbonImmutable;

final readonly class RescheduleBookingData
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public BookingActor $actor,
        public ?int $durationMinutes = null,
        public ?string $reason = null,
    ) {}
}
