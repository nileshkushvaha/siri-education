<?php

declare(strict_types=1);

namespace App\Earnings\Services;

use App\Earnings\Contracts\InstructorPayoutEligibilityServiceInterface;
use App\Earnings\Contracts\InstructorPayoutProviderRegistryInterface;
use App\Earnings\DTOs\PayoutEligibilityResult;
use App\Earnings\Enums\PayoutMethodType;
use App\Earnings\Enums\PayoutRolloutScope;
use App\Models\Country;
use App\Settings\InstructorEarningSettings;
use Carbon\CarbonImmutable;

/**
 * Deterministic route resolution: rollout scope narrows which
 * countries/currencies may even be considered, then the resolved
 * provider's own declared `PayoutProviderCapabilities` decides the
 * rest. No provider name ever appears in a conditional here — see
 * docs/payment-collection-and-payout-provider-routing.md §6.
 */
final class InstructorPayoutEligibilityService implements InstructorPayoutEligibilityServiceInterface
{
    public function __construct(
        private readonly InstructorEarningSettings $settings,
        private readonly InstructorPayoutProviderRegistryInterface $providers,
    ) {}

    public function resolve(
        ?string $instructorCountryIso2,
        ?string $destinationCountryIso2,
        string $withdrawalCurrency,
        PayoutMethodType $destinationType,
    ): PayoutEligibilityResult {
        $currencyCode = strtoupper($withdrawalCurrency);
        $failures = [];

        if (! $this->settings->payout_execution_enabled) {
            $failures[] = 'payout_execution_disabled';
        }

        $scope = PayoutRolloutScope::tryFrom($this->settings->payout_rollout_scope) ?? PayoutRolloutScope::Disabled;

        if ($scope === PayoutRolloutScope::Disabled) {
            $failures[] = 'country_route_missing';

            return $this->result(null, $instructorCountryIso2, $destinationCountryIso2, $currencyCode, $destinationType, $failures, null);
        }

        if ($scope === PayoutRolloutScope::IndiaInrOnly) {
            $isIndiaRoute = strtoupper((string) $instructorCountryIso2) === 'IN'
                && strtoupper((string) $destinationCountryIso2) === 'IN'
                && $currencyCode === 'INR';

            if (! $isIndiaRoute) {
                $failures[] = 'country_route_missing';

                return $this->result(null, $instructorCountryIso2, $destinationCountryIso2, $currencyCode, $destinationType, $failures, null);
            }
        }

        // Country-specific override (Phase 16A.1 plumbing — no country
        // has payout_routing configured yet; falls back to the single
        // configured provider, which is 'fake' throughout this phase).
        $providerKey = $this->countryRoutedProvider($destinationCountryIso2) ?? $this->settings->payout_provider;

        if (! $this->providers->has($providerKey)) {
            $failures[] = 'provider_not_configured';

            return $this->result($providerKey, $instructorCountryIso2, $destinationCountryIso2, $currencyCode, $destinationType, $failures, null);
        }

        $provider = $this->providers->get($providerKey);
        $capabilities = $provider->capabilities();

        if (! $capabilities->supportsInstructorCountry($instructorCountryIso2)) {
            $failures[] = 'unsupported_instructor_country';
        }

        if (! $capabilities->supportsDestinationCountry($destinationCountryIso2)) {
            $failures[] = 'unsupported_destination_country';
        }

        if (! $capabilities->supportsCurrency($currencyCode)) {
            $failures[] = 'unsupported_currency';
        }

        if (! $capabilities->supportsDestinationType($destinationType)) {
            $failures[] = 'unsupported_destination_type';
        }

        if (! $capabilities->healthStatus->healthy) {
            $failures[] = 'provider_unhealthy';
        }

        return $this->result($providerKey, $instructorCountryIso2, $destinationCountryIso2, $currencyCode, $destinationType, $failures, $capabilities->capabilityVersion);
    }

    private function countryRoutedProvider(?string $destinationCountryIso2): ?string
    {
        if ($destinationCountryIso2 === null) {
            return null;
        }

        $country = Country::query()->where('iso2', strtoupper($destinationCountryIso2))->first(['payout_routing']);
        $routing = $country?->payout_routing;

        if (! is_array($routing) || ($routing['enabled'] ?? true) === false) {
            return null;
        }

        $provider = $routing['provider'] ?? null;

        return filled($provider) ? (string) $provider : null;
    }

    /** @param list<string> $blockingCodes */
    private function result(
        ?string $provider,
        ?string $instructorCountry,
        ?string $destinationCountry,
        string $currency,
        PayoutMethodType $destinationType,
        array $blockingCodes,
        ?int $capabilityVersion,
    ): PayoutEligibilityResult {
        $messages = [
            'payout_execution_disabled' => 'Payout execution is currently disabled.',
            'country_route_missing' => 'No payout route is configured for this instructor/destination country.',
            'provider_not_configured' => 'The configured payout provider is not registered.',
            'unsupported_instructor_country' => 'This provider does not support the instructor\'s country.',
            'unsupported_destination_country' => 'This provider does not support the destination country.',
            'unsupported_currency' => sprintf('This provider does not support %s.', $currency),
            'unsupported_destination_type' => 'This provider does not support this destination type.',
            'provider_unhealthy' => 'The payout provider is not currently healthy.',
        ];

        return new PayoutEligibilityResult(
            isEligible: $blockingCodes === [],
            provider: $provider,
            instructorCountry: $instructorCountry,
            destinationCountry: $destinationCountry,
            withdrawalCurrency: $currency,
            destinationType: $destinationType->value,
            blockingCodes: $blockingCodes,
            safeMessages: array_values(array_intersect_key($messages, array_flip($blockingCodes))),
            evaluatedAt: CarbonImmutable::now(),
            capabilityVersion: $capabilityVersion,
        );
    }
}
