<?php

declare(strict_types=1);

namespace App\Listeners\Payment;

use App\Booking\Events\BookingPaymentSucceeded;
use App\Booking\Support\SettledBookingPaymentResolver;
use App\Services\Payment\InvoiceService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Thin trigger — all generation logic lives in InvoiceService.
 * BookingPaymentSucceeded carries only the Booking, not the specific
 * BookingPayment row, so this listener resolves the captured attempt
 * through SettledBookingPaymentResolver — the same resolver
 * SendBookingNotifications uses, so the receipt and the student's
 * payment email can never describe two different attempts. Queued and
 * idempotent: a redelivered event resolves the same payment and
 * InvoiceService returns the already-generated invoice rather than a
 * duplicate.
 */
final class GenerateInvoiceOnBookingPaymentSucceeded implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly SettledBookingPaymentResolver $payments,
    ) {}

    public function handle(BookingPaymentSucceeded $event): void
    {
        $payment = $this->payments->resolve($event->booking);

        if ($payment === null) {
            return;
        }

        $this->invoices->generateForBookingPayment($payment);
    }
}
