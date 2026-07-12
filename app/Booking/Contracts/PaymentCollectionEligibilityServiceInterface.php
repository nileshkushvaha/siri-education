<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\PaymentEligibilityResult;

/**
 * Resolves whether a collection ROUTE (student country + billing
 * currency + transaction type + payment method) can be serviced by a
 * provider — combining `PaymentProviderResolver`'s existing, tested
 * routing order with each provider's declared
 * `PaymentProviderCapabilities`. A read-only preview: this never
 * initiates a payment, never mutates state.
 */
interface PaymentCollectionEligibilityServiceInterface
{
    public function resolve(
        ?string $studentCountryIso2,
        string $billingCurrency,
        string $transactionType,
        ?string $paymentMethod = null,
    ): PaymentEligibilityResult;
}
