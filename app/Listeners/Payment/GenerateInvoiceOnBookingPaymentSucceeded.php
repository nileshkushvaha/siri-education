<?php

declare(strict_types=1);

namespace App\Listeners\Payment;

use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Events\BookingPaymentSucceeded;
use App\Models\BookingPayment;
use App\Services\Payment\InvoiceService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Thin trigger — all generation logic lives in InvoiceService.
 * BookingPaymentSucceeded carries only the Booking, not the specific
 * BookingPayment row, so this listener resolves the captured attempt
 * itself using the same "latest captured payment for this booking"
 * convention already established in BookingPaymentService::recordRefund()/
 * lockedUnresolvedCapturedPayment(). Queued and idempotent: a
 * redelivered event resolves the same payment and InvoiceService
 * returns the already-generated invoice rather than a duplicate.
 */
final class GenerateInvoiceOnBookingPaymentSucceeded implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly InvoiceService $invoices,
    ) {}

    public function handle(BookingPaymentSucceeded $event): void
    {
        $payment = BookingPayment::query()
            ->where('booking_id', $event->booking->id)
            ->where('status', BookingPaymentRecordStatus::Captured)
            ->latest('created_at')
            ->first();

        if ($payment === null) {
            return;
        }

        $this->invoices->generateForBookingPayment($payment);
    }
}
