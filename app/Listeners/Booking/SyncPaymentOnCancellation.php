<?php

declare(strict_types=1);

namespace App\Listeners\Booking;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Events\BookingCancelled;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Status sync, cancellation → payment: cancelling a paid booking
 * refunds it automatically to the student's wallet (Phase 16A.1
 * "Version 1" policy — never the gateway; see
 * BookingPaymentServiceInterface::refundToWallet()). Refund-triggered
 * cancellations are already Refunded by the time this runs, so no loop
 * is possible.
 *
 * Phase 24C: never recomputes eligibility — $event->refundDecision was
 * already frozen synchronously inside BookingService::cancel(), before
 * this event ever dispatched, so a delayed queue worker can't observe
 * a since-changed cancellation-window setting or a later clock. A null
 * decision means BookingService::cancel() found nothing to decide
 * about (payment_status wasn't Paid at cancellation time); this
 * listener's own guard below is a defensive second check for the same
 * fact, not a place that recomputes anything.
 */
final class SyncPaymentOnCancellation implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly BookingPaymentServiceInterface $payments,
    ) {}

    public function handle(BookingCancelled $event): void
    {
        if ($event->booking->payment_status !== BookingPaymentStatus::Paid) {
            return;
        }

        $decision = $event->refundDecision;

        if ($decision !== null && ! $decision->eligible) {
            $this->payments->recordIneligibleCancellation($event->booking, $decision);

            return;
        }

        $this->payments->refundToWallet($event->booking, 'Booking cancelled', $decision);
    }
}
