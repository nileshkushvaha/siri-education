<?php

declare(strict_types=1);

namespace App\Earnings\Contracts;

use App\Earnings\Exceptions\EarningException;
use App\Models\LessonFinancialDisposition;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Phase 17G — instructor-side earning reconciliation for approved
 * dispositions: create a missing earning, restore/release, hold, or
 * reverse — all through the existing earning service. Never touches
 * student wallets, payments, or settled money.
 */
interface LessonEarningReconciliationServiceInterface
{
    /**
     * Execute one approved reconciliation. Idempotent: repeats and
     * concurrent workers adjust exactly once. With $admin the call is
     * permission-checked and attributed; null is the system path
     * (command) acting for admin-approved records.
     *
     * @throws EarningException
     * @throws AuthorizationException
     */
    public function execute(LessonFinancialDisposition $disposition, ?User $admin = null): LessonFinancialDisposition;

    /**
     * Process every approved Ready reconciliation disposition in
     * deterministic batches with per-record failure isolation. Returns
     * the number of reconciliations applied. No-op while
     * instructor_earnings.earning_reconciliation_execution_enabled is off.
     */
    public function processReady(): int;
}
