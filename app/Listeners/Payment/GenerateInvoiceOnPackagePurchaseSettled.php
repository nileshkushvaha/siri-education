<?php

declare(strict_types=1);

namespace App\Listeners\Payment;

use App\Package\Events\PackagePurchaseSettled;
use App\Services\Payment\InvoiceService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Thin trigger — all generation logic lives in InvoiceService, the
 * single authoritative writer of `invoices`. Mirrors
 * GenerateInvoiceOnBookingPaymentSucceeded so a package receipt is
 * produced by the same mechanism as a booking one, and exists
 * independently of whether the student's notification was deliverable:
 * the financial record must not depend on the mail provider.
 *
 * Queued and idempotent — a redelivered event returns the
 * already-generated invoice rather than a duplicate.
 */
final class GenerateInvoiceOnPackagePurchaseSettled implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly InvoiceService $invoices,
    ) {}

    public function handle(PackagePurchaseSettled $event): void
    {
        $this->invoices->generateForPackagePurchase($event->payment);
    }
}
