<?php

declare(strict_types=1);

namespace App\Earnings\Contracts;

use App\Earnings\DTOs\FeatureReadiness;
use App\Earnings\Exceptions\CompensationException;
use App\Models\User;

/**
 * The ONLY write path for the three financial feature switches
 * (earnings_enabled, periodic_compensation_enabled,
 * withdrawals_enabled). Every enable operation runs its activation
 * preflight and refuses on any failure; the settings class itself
 * rejects switch changes from any other caller. All toggles are
 * audited with the acting administrator.
 */
interface FinancialFeatureConfigurationServiceInterface
{
    public function evaluateEarningsReadiness(): FeatureReadiness;

    public function evaluatePeriodicCompensationReadiness(): FeatureReadiness;

    public function evaluateWithdrawalReadiness(): FeatureReadiness;

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
}
