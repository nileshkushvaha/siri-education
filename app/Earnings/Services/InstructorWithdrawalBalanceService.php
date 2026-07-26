<?php

declare(strict_types=1);

namespace App\Earnings\Services;

use App\Earnings\Contracts\InstructorWithdrawalBalanceServiceInterface;
use App\Earnings\DTOs\WithdrawalBalance;
use App\Earnings\Enums\PayoutMethodStatus;
use App\Earnings\Enums\WithdrawalAllocationStatus;
use App\Earnings\Support\InstructorPayoutEligibility;
use App\Models\Currency;
use App\Models\InstructorEarning;
use App\Models\InstructorPayoutMethod;
use App\Models\InstructorWithdrawalAllocation;
use App\Models\User;
use App\Settings\InstructorEarningSettings;
use Carbon\CarbonImmutable;

/**
 * Available-withdrawal balance, always derived from canonical records:
 * releasable, batch-unassigned earnings minus live (reserved /
 * consumed) allocation amounts, per currency. There is no stored
 * balance column anywhere — this aggregate IS the balance, and request
 * submission recomputes it under row locks.
 */
final class InstructorWithdrawalBalanceService implements InstructorWithdrawalBalanceServiceInterface
{
    public function __construct(
        private readonly InstructorEarningSettings $settings,
        private readonly InstructorPayoutEligibility $eligibility,
    ) {}

    public function calculate(User $instructor, string $currencyCode, bool $forUpdate = false): WithdrawalBalance
    {
        $currencyCode = strtoupper($currencyCode);

        $earningsQuery = InstructorEarning::query()->withdrawable($instructor->id, $currencyCode);

        if ($forUpdate) {
            // Materialize the locked rows so the sums see a stable set.
            $gross = (int) $earningsQuery->lockForUpdate()->get()->sum('earning_amount_minor');
        } else {
            $gross = (int) $earningsQuery->sum('earning_amount_minor');
        }

        // Reserved AND consumed both stay subtracted: consumed means the
        // money left the platform (execution phase) even if the earning
        // row has not flipped to settled yet.
        $reserved = (int) InstructorWithdrawalAllocation::query()
            ->whereIn('status', [WithdrawalAllocationStatus::Reserved, WithdrawalAllocationStatus::Consumed])
            ->whereHas('earning', fn ($q) => $q->withdrawable($instructor->id, $currencyCode))
            ->sum('amount_minor');

        $available = max(0, $gross - $reserved);

        [$canWithdraw, $blockingReason] = $this->withdrawability($instructor, $available);

        return new WithdrawalBalance(
            instructorId: $instructor->id,
            currencyId: Currency::query()->where('code', $currencyCode)->value('id'),
            currencyCode: $currencyCode,
            grossEligibleMinor: $gross,
            reservedMinor: $reserved,
            availableMinor: $available,
            minimumWithdrawalMinor: $this->settings->minimum_withdrawal_minor,
            maximumWithdrawalMinor: $this->settings->maximum_withdrawal_minor,
            canWithdraw: $canWithdraw,
            blockingReason: $blockingReason,
            calculatedAt: CarbonImmutable::now(),
        );
    }

    public function currenciesWithBalance(User $instructor): array
    {
        return InstructorEarning::query()
            ->settleable()
            ->where('instructor_id', $instructor->id)
            ->distinct()
            ->orderBy('currency_code')
            ->pluck('currency_code')
            ->all();
    }

    /** @return array{0: bool, 1: ?string} */
    private function withdrawability(User $instructor, int $available): array
    {
        if (! $this->settings->withdrawals_enabled) {
            return [false, 'Withdrawals are currently disabled.'];
        }

        if (($reason = $this->eligibility->reasonForIneligibility($instructor)) !== null) {
            return [false, $reason];
        }

        if ($this->settings->payout_method_verification_required
            && ! InstructorPayoutMethod::query()->forInstructor($instructor->id)->where('status', PayoutMethodStatus::Verified)->exists()) {
            return [false, 'You need a verified payout method before requesting a withdrawal.'];
        }

        if ($available < $this->settings->minimum_withdrawal_minor) {
            return [false, 'Your available balance is below the minimum withdrawal amount.'];
        }

        return [true, null];
    }
}
