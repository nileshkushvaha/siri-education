<?php

declare(strict_types=1);

namespace App\Wallet\Contracts;

use App\Earnings\Exceptions\EarningException;
use App\Models\LessonFinancialDisposition;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Phase 17F — wallet-only refund execution for approved dispositions.
 * Version 1 never calls a gateway refund API: gateway-paid lessons are
 * credited to the student wallet and the original payment record is
 * preserved. All balance changes flow through WalletLedgerService.
 */
interface LessonWalletRefundServiceInterface
{
    /**
     * Execute one approved refund disposition. Idempotent: repeats and
     * concurrent workers return the single existing ledger credit.
     * When $admin is given the action is permission-checked and the
     * admin becomes the ledger/audit actor; a null admin is the system
     * path (command) acting for admin-approved records.
     *
     * @throws EarningException
     * @throws AuthorizationException
     */
    public function execute(LessonFinancialDisposition $disposition, ?User $admin = null): LessonFinancialDisposition;

    /**
     * Process every Ready full-wallet-refund disposition in
     * deterministic batches with per-record failure isolation. Returns
     * the number of refunds credited. No-op while
     * instructor_earnings.lesson_refund_execution_enabled is off.
     */
    public function processReady(): int;
}
