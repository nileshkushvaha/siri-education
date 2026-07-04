<?php

declare(strict_types=1);

namespace App\Booking\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Covers both Completed and NoShow outcomes — read $booking->status. */
final class BookingCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Booking $booking,
    ) {}
}
