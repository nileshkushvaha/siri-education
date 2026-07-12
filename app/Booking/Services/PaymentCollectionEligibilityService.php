<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\PaymentCollectionEligibilityServiceInterface;
use App\Booking\DTOs\PaymentEligibilityResult;
use App\Booking\Enums\PaymentCollectionRolloutScope;
use App\Booking\Exceptions\BookingException;
use App\Settings\PaymentGatewaySettings;
use Carbon\CarbonImmutable;

/**
 * Deterministic route resolution: rollout scope narrows which
 * countries are even considered, `PaymentProviderResolver` (the
 * existing, tested routing seam) picks the provider, then the
 * resolved provider's own declared `PaymentProviderCapabilities`
 * decides the rest. No provider name ever appears in a conditional
 * here — see docs/payment-collection-and-payout-provider-routing.md §4.
 * Read-only: never initiates a payment.
 */
final class PaymentCollectionEligibilityService implements PaymentCollectionEligibilityServiceInterface
{
    public function __construct(
        private readonly PaymentGatewaySettings $settings,
        private readonly PaymentProviderResolver $resolver,
    ) {}

    public function resolve(
        ?string $studentCountryIso2,
        string $billingCurrency,
        string $transactionType,
        ?string $paymentMethod = null,
    ): PaymentEligibilityResult {
        $currencyCode = strtoupper($billingCurrency);
        $failures = [];

        if (! $this->settings->payments_enabled) {
            $failures[] = 'payment_collection_disabled';
        }

        $scope = PaymentCollectionRolloutScope::tryFrom($this->settings->payment_collection_rollout_scope) ?? PaymentCollectionRolloutScope::Disabled;

        if ($scope === PaymentCollectionRolloutScope::Disabled) {
            $failures[] = 'country_route_missing';

            return $this->result(null, $studentCountryIso2, $currencyCode, $transactionType, $paymentMethod, $failures, null);
        }

        if ($scope === PaymentCollectionRolloutScope::IndiaRazorpayOnly && strtoupper((string) $studentCountryIso2) !== 'IN') {
            $failures[] = 'country_route_missing';

            return $this->result(null, $studentCountryIso2, $currencyCode, $transactionType, $paymentMethod, $failures, null);
        }

        try {
            $provider = $this->resolver->current($studentCountryIso2);
        } catch (BookingException $e) {
            $failures[] = $this->classifyResolverFailure($e->getMessage());

            return $this->result(null, $studentCountryIso2, $currencyCode, $transactionType, $paymentMethod, $failures, null);
        }

        $capabilities = $provider->capabilities();

        if (! $capabilities->supportsStudentCountry($studentCountryIso2)) {
            $failures[] = 'unsupported_student_country';
        }

        if (! $capabilities->supportsBillingCurrency($currencyCode)) {
            $failures[] = 'unsupported_billing_currency';
        }

        if (! $capabilities->supportsTransactionType($transactionType)) {
            $failures[] = 'unsupported_transaction_type';
        }

        if (! $capabilities->healthStatus->healthy) {
            $failures[] = 'provider_unhealthy';
        }

        return $this->result($provider->key(), $studentCountryIso2, $currencyCode, $transactionType, $paymentMethod, $failures, $capabilities->capabilityVersion);
    }

    /** PaymentProviderResolver throws plain BookingException with a human message — map its known phrasings to a stable code. */
    private function classifyResolverFailure(string $message): string
    {
        return match (true) {
            str_contains($message, 'disabled platform-wide') => 'payment_collection_disabled',
            str_contains($message, 'not in the platform\'s allowed-provider list') => 'provider_disabled',
            str_contains($message, 'cannot be used outside local/testing') => 'provider_disabled',
            str_contains($message, 'disabled') => 'provider_disabled',
            str_contains($message, 'not enabled or its credentials are missing') => 'credentials_invalid',
            default => 'provider_not_configured',
        };
    }

    /** @param list<string> $blockingCodes */
    private function result(
        ?string $provider,
        ?string $studentCountry,
        string $currency,
        string $transactionType,
        ?string $paymentMethod,
        array $blockingCodes,
        ?int $capabilityVersion,
    ): PaymentEligibilityResult {
        $messages = [
            'payment_collection_disabled' => 'Payments are currently disabled platform-wide.',
            'country_route_missing' => 'No collection route is configured for this country.',
            'provider_not_configured' => 'The configured payment provider is not registered.',
            'provider_disabled' => 'The configured payment provider is disabled.',
            'credentials_invalid' => 'The configured payment provider\'s credentials are missing or invalid.',
            'unsupported_student_country' => 'This provider does not support your country.',
            'unsupported_billing_currency' => sprintf('This provider does not support %s.', $currency),
            'unsupported_transaction_type' => 'This provider does not support this transaction type.',
            'provider_unhealthy' => 'The payment provider is not currently healthy.',
        ];

        return new PaymentEligibilityResult(
            isEligible: $blockingCodes === [],
            provider: $provider,
            studentCountry: $studentCountry,
            billingCurrency: $currency,
            transactionType: $transactionType,
            paymentMethod: $paymentMethod,
            blockingCodes: $blockingCodes,
            safeMessages: array_values(array_intersect_key($messages, array_flip($blockingCodes))),
            evaluatedAt: CarbonImmutable::now(),
            capabilityVersion: $capabilityVersion,
        );
    }
}
