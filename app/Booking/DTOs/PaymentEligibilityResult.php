<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use Carbon\CarbonImmutable;

/**
 * The result of resolving a COLLECTION route (student country + billing
 * currency + transaction type + payment method) against provider
 * capabilities and routing configuration. Collection-side counterpart
 * of `App\Earnings\DTOs\PayoutEligibilityResult` — same shape of idea,
 * a distinct class, because the two questions ("can we collect from
 * this student" vs. "can we pay this instructor") share no state.
 */
final readonly class PaymentEligibilityResult
{
    /**
     * @param  list<string>  $blockingCodes
     * @param  list<string>  $safeMessages
     */
    public function __construct(
        public bool $isEligible,
        public ?string $provider,
        public ?string $studentCountry,
        public string $billingCurrency,
        public string $transactionType,
        public ?string $paymentMethod,
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
