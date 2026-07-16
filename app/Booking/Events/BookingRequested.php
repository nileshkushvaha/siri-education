<?php

declare(strict_types=1);

namespace App\Booking\Events;

use App\Models\Booking;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by BookingService after a booking is persisted.
 * Listeners feed AuditTrailService; notifications then flow from
 * the Activity Log pipeline (docs/decisions.md). ShouldDispatchAfterCommit
 * because request() can be called from inside a caller's own outer
 * transaction — queued listeners must never observe a booking row
 * that isn't durably committed yet (Phase 17U.4).
 */
final class BookingRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Booking $booking,
    ) {}
}
