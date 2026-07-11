<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Earnings\Contracts\InstructorPayoutReconciliationServiceInterface;
use Illuminate\Console\Command;

/**
 * Scheduled reconciliation sweep (Phase 16A). Gated by
 * `payout_reconciliation_enabled` inside the service; idempotent —
 * every state transition it makes reuses
 * InstructorPayoutExecutionService::applyProviderStatus(), so a
 * duplicate/overlapping run can never apply a financial effect twice.
 * Phase 16A only ever reconciles against the fake provider.
 */
final class ReconcileInstructorPayouts extends Command
{
    protected $signature = 'instructor-payouts:reconcile {--limit=200}';

    protected $description = 'Poll due payout attempts for a confirmed provider outcome and resolve mismatches.';

    public function handle(InstructorPayoutReconciliationServiceInterface $reconciliation): int
    {
        $examined = $reconciliation->reconcileDue((int) $this->option('limit'));

        $this->info("Reconciliation examined {$examined} due payout attempt(s).");

        return self::SUCCESS;
    }
}
