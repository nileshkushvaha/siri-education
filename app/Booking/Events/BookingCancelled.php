<?php

declare(strict_types=1);

namespace App\Booking\Events;

use App\Booking\DTOs\CancelBookingData;
use App\Models\Booking;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ShouldDispatchAfterCommit: BookingPaymentService::finalizeRefundedBooking()
 * calls BookingService::cancel() from inside its own outer DB::transaction
 * (three call sites: refundViaProvider(), recordRefund(), the wallet-credit
 * path) — queued listeners must never observe a cancellation that isn't
 * durably committed yet (Phase 17U.4).
 */
final class BookingCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Booking $booking,
        public readonly CancelBookingData $context,
    ) {}
}
