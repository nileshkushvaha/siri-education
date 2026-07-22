<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Wallet\Services\WalletRechargeReconciliationService;
use Illuminate\Console\Command;

/**
 * Scheduled reconciliation sweep for wallet recharges (SRS §13.33) —
 * the wallet-domain counterpart of ReconcileBookingPayments, never
 * sharing a table or code path with it. Idempotent: every state
 * transition reuses WalletRechargeService::processProviderEvent()/
 * retryPendingCredit(), so an overlapping run can never apply a
 * financial effect twice.
 */
final class ReconcileWalletRecharges extends Command
{
    protected $signature = 'wallet-recharges:reconcile {--limit=200}';

    protected $description = 'Poll due wallet recharge attempts for a confirmed provider outcome and retry uncredited captures.';

    public function handle(WalletRechargeReconciliationService $reconciliation): int
    {
        $examined = $reconciliation->reconcileDue((int) $this->option('limit'));

        $this->info("Reconciliation examined {$examined} due wallet recharge attempt(s).");

        return self::SUCCESS;
    }
}
