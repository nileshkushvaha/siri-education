<?php

declare(strict_types=1);

namespace App\Listeners\Wallet;

use App\Services\Payment\InvoiceService;
use App\Wallet\Events\WalletRechargeSucceeded;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Thin trigger — all generation logic lives in InvoiceService. Queued
 * and idempotent: a redelivered event resolves to the same recharge
 * and InvoiceService returns the already-generated invoice rather
 * than a duplicate.
 */
final class GenerateInvoiceOnWalletRechargeSucceeded implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly InvoiceService $invoices,
    ) {}

    public function handle(WalletRechargeSucceeded $event): void
    {
        $this->invoices->generateForWalletRecharge($event->recharge);
    }
}
