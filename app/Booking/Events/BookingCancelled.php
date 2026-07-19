<?php

declare(strict_types=1);

namespace App\Booking\Events;

use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\CancellationRefundDecision;
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
 *
 * Phase 24C: $refundDecision is the frozen CancellationRefundPolicy
 * outcome, computed synchronously inside BookingService::cancel()
 * before this event ever dispatches — null whenever the booking had no
 * captured payment to decide about (free demo, unpaid/failed, already
 * refunded). Both SyncPaymentOnCancellation (execution) and
 * SendBookingNotifications (messaging) read this SAME frozen value
 * independently, so neither can observe a different answer than the
 * other, or one computed later against a changed setting/clock.
 */
final class BookingCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Booking $booking,
        public readonly CancelBookingData $context,
        public readonly ?CancellationRefundDecision $refundDecision = null,
    ) {}
}
