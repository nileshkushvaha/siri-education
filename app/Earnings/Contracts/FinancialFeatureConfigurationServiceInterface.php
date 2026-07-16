<?php

declare(strict_types=1);

namespace App\Earnings\Contracts;

use App\Earnings\DTOs\FeatureReadiness;
use App\Earnings\Exceptions\CompensationException;
use App\Models\User;

/**
 * The ONLY write path for the seven financial feature switches
 * (earnings_enabled, periodic_compensation_enabled,
 * withdrawals_enabled, payout_execution_enabled,
 * financial_disposition_enabled, lesson_refund_execution_enabled,
 * earning_reconciliation_execution_enabled). Every enable operation
 * runs its activation preflight and refuses on any failure; the
 * settings class itself rejects switch changes from any other caller.
 * All toggles are audited with the acting administrator.
 */
interface FinancialFeatureConfigurationServiceInterface
{
    public function evaluateEarningsReadiness(): FeatureReadiness;

    public function evaluatePeriodicCompensationReadiness(): FeatureReadiness;

    public function evaluateWithdrawalReadiness(): FeatureReadiness;

    public function evaluatePayoutExecutionReadiness(): FeatureReadiness;

    public function evaluateFinancialDispositionReadiness(): FeatureReadiness;

    public function evaluateLessonRefundExecutionReadiness(): FeatureReadiness;

    public function evaluateEarningReconciliationExecutionReadiness(): FeatureReadiness;

    /** @throws CompensationException when the preflight blocks */
    public function enableEarnings(User $actor): FeatureReadiness;

    /** Transactionally auto-disables periodic compensation (documented rule). */
    public function disableEarnings(User $actor): void;

    /** @throws CompensationException when the preflight blocks */
    public function enablePeriodicCompensation(User $actor): FeatureReadiness;

    public function disablePeriodicCompensation(User $actor): void;

    /** @throws CompensationException when the preflight blocks */
    public function enableWithdrawals(User $actor): FeatureReadiness;

    public function disableWithdrawals(User $actor): void;

    /** @throws CompensationException when the preflight blocks */
    public function enablePayoutExecution(User $actor): FeatureReadiness;

    public function disablePayoutExecution(User $actor): void;

    /** @throws CompensationException when the preflight blocks */
    public function enableFinancialDisposition(User $actor): FeatureReadiness;

    /** Transactionally auto-disables refund and earning-reconciliation execution (both depend on classification). */
    public function disableFinancialDisposition(User $actor): void;

    /** @throws CompensationException when the preflight blocks */
    public function enableLessonRefundExecution(User $actor): FeatureReadiness;

    public function disableLessonRefundExecution(User $actor): void;

    /** @throws CompensationException when the preflight blocks */
    public function enableEarningReconciliationExecution(User $actor): FeatureReadiness;

    public function disableEarningReconciliationExecution(User $actor): void;
}
