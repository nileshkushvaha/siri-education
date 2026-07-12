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

        $this->payments->refundToWallet($event->booking, 'Booking cancelled');
    }
}
