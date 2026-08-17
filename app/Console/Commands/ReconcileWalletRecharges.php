<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Wallet\Services\WalletRechargeReconciliationService;
use Illuminate\Console\Command;

/**
 * Scheduled reconciliation sweep for wallet recharges (SRS §13.33).
 *
 * Sweeps the recharge slice of the generic `payments` ledger, using the
 * shared PaymentAttemptVerifier to ask the provider and the shared
 * WalletRechargeSettlementService to apply the answer — so it can never
 * disagree with the webhook about what "paid" means. It also retries
 * captures whose wallet CREDIT failed, which needs no provider call at
 * all and is the one recovery no generic sweep would look for.
 *
 * Idempotent at every layer: Payment terminal states, recharge
 * settlement state, and the ledger's unique idempotency key. An
 * overlapping run cannot apply a financial effect twice.
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
