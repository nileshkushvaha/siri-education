<?php

declare(strict_types=1);

namespace App\Booking\Events;

use App\Models\Booking;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A provider-verified payment settled this booking (payment_status →
 * Paid). Dispatched exactly once, from BookingPaymentService::markPaid()
 * after its transaction — never from payment initiation, frontend
 * checkout success, or Option B's late-terminal path (which never
 * reaches markPaid()'s settle branch). A duplicate webhook cannot
 * re-fire it: markPaid() requires payment_status === Pending.
 * ShouldDispatchAfterCommit: kept consistent with the rest of the
 * Booking event family — a queued listener must never observe a
 * payment settlement that isn't durably committed yet (Phase 17U.4).
 */
final class BookingPaymentSucceeded implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Booking $booking,
    ) {}
}
