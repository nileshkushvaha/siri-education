<?php

declare(strict_types=1);

namespace App\Booking\Events;

use App\Booking\DTOs\CancelBookingData;
use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class BookingCancelled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Booking $booking,
        public readonly CancelBookingData $context,
    ) {}
}
