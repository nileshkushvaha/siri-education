<?php

declare(strict_types=1);

namespace App\Earnings\Contracts;

use App\Earnings\DTOs\PayoutEligibilityResult;
use App\Earnings\Enums\PayoutMethodType;

/**
 * Resolves whether a payout ROUTE (instructor country + destination
 * country + withdrawal currency + destination type) can be serviced by
 * a provider — combining provider capabilities with routing
 * configuration (`payout_rollout_scope`, `Country.payout_routing`).
 * Never confuse with `App\Earnings\Support\InstructorPayoutEligibility`,
 * which is an account-level check (role, active status,
 * instructor application status) with no notion of provider/geography
 * at all — a user can be perfectly eligible per that class and still
 * have no eligible payout ROUTE per this one, and vice versa is
 * meaningless (route eligibility is irrelevant if the account itself
 * cannot use payouts).
 */
interface InstructorPayoutEligibilityServiceInterface
{
    public function resolve(
        ?string $instructorCountryIso2,
        ?string $destinationCountryIso2,
        string $withdrawalCurrency,
        PayoutMethodType $destinationType,
    ): PayoutEligibilityResult;
}
