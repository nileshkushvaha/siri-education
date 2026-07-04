<?php

declare(strict_types=1);

namespace App\Booking\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by BookingService after a booking is persisted.
 * Listeners feed AuditTrailService; notifications then flow from
 * the Activity Log pipeline (docs/decisions.md).
 */
final class BookingRequested
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Booking $booking,
    ) {}
}
