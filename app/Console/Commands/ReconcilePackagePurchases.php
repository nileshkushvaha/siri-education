<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Package\Services\PackagePurchaseReconciliationService;
use Illuminate\Console\Command;

/**
 * Scheduled reconciliation sweep for package payments — the package
 * counterpart of ReconcileWalletRecharges and ReconcileBookingPayments,
 * never sharing a table or code path with either. Idempotent: every
 * recovery goes through PackagePurchaseSettlementService, so an
 * overlapping run is wasted work, never a duplicate activation.
 */
final class ReconcilePackagePurchases extends Command
{
    protected $signature = 'package-purchases:reconcile {--limit=200}';

    protected $description = 'Poll due package payment attempts for a confirmed provider outcome and activate any that were paid.';

    public function handle(PackagePurchaseReconciliationService $reconciliation): int
    {
        $examined = $reconciliation->reconcileDue((int) $this->option('limit'));

        $this->info("Reconciliation examined {$examined} due package payment attempt(s).");

        return self::SUCCESS;
    }
}
