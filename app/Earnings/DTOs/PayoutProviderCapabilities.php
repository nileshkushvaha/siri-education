<?php

declare(strict_types=1);

namespace App\Earnings\DTOs;

use App\Earnings\Enums\PayoutMethodType;

/**
 * A provider's static, declared shape — what it can do, not whether a
 * specific route may use it right now (that is
 * `InstructorPayoutEligibilityService`, which combines this with
 * routing configuration). Every provider adapter (the fake provider
 * today; a real one outside this phase) returns one of these from
 * `InstructorPayoutProviderInterface::capabilities()`; nothing in the
 * generic payout domain branches on a provider name — it reads this
 * DTO instead.
 */
final readonly class PayoutProviderCapabilities
{
    /**
     * @param  list<string>  $supportedInstructorCountries  ISO2 codes; empty = no restriction
     * @param  list<string>  $supportedDestinationCountries  ISO2 codes; empty = no restriction
     * @param  list<string>  $supportedCurrencies  ISO 4217 codes
     * @param  list<PayoutMethodType>  $supportedDestinationTypes
     * @param  list<string>  $supportedTransferModes  provider-specific rail names (e.g. imps/neft/rtgs); empty = provider decides internally
     */
    public function __construct(
        public string $provider,
        public string $environment,
        public array $supportedInstructorCountries,
        public array $supportedDestinationCountries,
        public array $supportedCurrencies,
        public array $supportedDestinationTypes,
        public array $supportedTransferModes,
        public bool $supportsStatusFetch,
        public bool $supportsWebhooks,
        public bool $supportsCancellation,
        public bool $supportsReversalEvents,
        public bool $supportsIdempotency,
        public bool $requiresContact,
        public bool $requiresFundAccount,
        public bool $requiresIpAllowlisting,
        public PayoutProviderHealth $healthStatus,
        public int $capabilityVersion,
    ) {}

    public function supportsInstructorCountry(?string $iso2): bool
    {
        return $this->supportedInstructorCountries === [] || ($iso2 !== null && in_array(strtoupper($iso2), $this->supportedInstructorCountries, true));
    }

    public function supportsDestinationCountry(?string $iso2): bool
    {
        return $this->supportedDestinationCountries === [] || ($iso2 !== null && in_array(strtoupper($iso2), $this->supportedDestinationCountries, true));
    }

    public function supportsCurrency(string $currencyCode): bool
    {
        return in_array(strtoupper($currencyCode), $this->supportedCurrencies, true);
    }

    public function supportsDestinationType(PayoutMethodType $type): bool
    {
        return in_array($type, $this->supportedDestinationTypes, true);
    }
}
