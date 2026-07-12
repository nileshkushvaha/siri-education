<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

/**
 * A collection provider's static, declared shape — what it can do, not
 * whether a specific checkout may use it right now (that is
 * `PaymentCollectionEligibilityService`, which combines this with
 * routing configuration). Every provider adapter returns one of these
 * from `PaymentProviderInterface::capabilities()`; nothing in the
 * generic booking domain branches on a provider name — it reads this
 * DTO instead. Deliberately the collection-side counterpart of
 * `App\Earnings\DTOs\PayoutProviderCapabilities` — same shape of idea,
 * never the same class (see docs/payment-collection-and-payout-provider-routing.md §3).
 */
final readonly class PaymentProviderCapabilities
{
    /**
     * @param  list<string>  $supportedStudentCountries  ISO2 codes; empty = no restriction
     * @param  list<string>  $supportedBillingCurrencies  ISO 4217 codes
     * @param  list<string>  $supportedCollectionCurrencies  ISO 4217 codes actually chargeable (usually == billing currencies)
     * @param  list<string>  $supportedTransactionTypes  wallet_recharge|booking_payment|recurring_wallet_funding
     * @param  list<string>  $supportedPaymentMethods  provider-specific method names; empty = provider decides internally
     */
    public function __construct(
        public string $provider,
        public string $environment,
        public array $supportedStudentCountries,
        public array $supportedBillingCurrencies,
        public array $supportedCollectionCurrencies,
        public array $supportedTransactionTypes,
        public array $supportedPaymentMethods,
        public bool $supportsWalletRecharge,
        public bool $supportsDirectBookingPayment,
        public bool $supportsStatusFetch,
        public bool $supportsWebhooks,
        public bool $supportsRefunds,
        public bool $supportsPartialRefunds,
        public bool $supportsAsyncConfirmation,
        public bool $supportsIdempotency,
        public bool $requiresCustomerCreation,
        public bool $requiresReturnUrl,
        public bool $requiresWebhookSignature,
        public PaymentProviderHealth $healthStatus,
        public int $capabilityVersion,
    ) {}

    public function supportsStudentCountry(?string $iso2): bool
    {
        return $this->supportedStudentCountries === [] || ($iso2 !== null && in_array(strtoupper($iso2), $this->supportedStudentCountries, true));
    }

    public function supportsBillingCurrency(string $currencyCode): bool
    {
        return in_array(strtoupper($currencyCode), $this->supportedBillingCurrencies, true);
    }

    public function supportsTransactionType(string $transactionType): bool
    {
        return in_array($transactionType, $this->supportedTransactionTypes, true);
    }
}
