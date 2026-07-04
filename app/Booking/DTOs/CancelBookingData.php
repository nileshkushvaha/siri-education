<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use App\Booking\Enums\BookingActor;

final readonly class CancelBookingData
{
    public function __construct(
        public BookingActor $cancelledBy,
        public ?string $reason = null,
    ) {}
}
