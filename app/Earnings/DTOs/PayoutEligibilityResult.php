<?php

declare(strict_types=1);

namespace App\Earnings\DTOs;

use Carbon\CarbonImmutable;

/**
 * The result of resolving a payout ROUTE (instructor country +
 * destination country + withdrawal currency + destination type)
 * against provider capabilities and routing configuration. Distinct
 * from `App\Earnings\Support\InstructorPayoutEligibility`, which
 * answers a different question — "is this user's ACCOUNT allowed
 * to hold payout methods / request withdrawals at all" (role, active
 * status, instructor application status). This DTO never touches
 * account state; it is purely about whether a provider can service a
 * given geography/currency/destination-type combination.
 */
final readonly class PayoutEligibilityResult
{
    /**
     * @param  list<string>  $blockingCodes
     * @param  list<string>  $safeMessages
     */
    public function __construct(
        public bool $isEligible,
        public ?string $provider,
        public ?string $instructorCountry,
        public ?string $destinationCountry,
        public string $withdrawalCurrency,
        public ?string $destinationType,
        public array $blockingCodes,
        public array $safeMessages,
        public CarbonImmutable $evaluatedAt,
        public ?int $capabilityVersion,
    ) {}

    public function summary(): string
    {
        return $this->isEligible ? 'Eligible.' : implode(' ', $this->safeMessages);
    }
}
