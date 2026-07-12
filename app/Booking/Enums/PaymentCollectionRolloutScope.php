<?php

declare(strict_types=1);

namespace App\Booking\Enums;

/**
 * Rollout POLICY, not a kill switch — `PaymentGatewaySettings::payments_enabled`
 * remains the authoritative switch. Narrows which routes are even
 * considered before `PaymentCollectionEligibilityService` checks
 * provider capabilities. Defaults to `india_razorpay_only`, matching
 * the platform's current safe state (Razorpay is the only provider
 * with a verified, tested end-to-end checkout flow; Stripe's frontend
 * integration does not exist yet — see StripePaymentProvider's own
 * docblock).
 */
enum PaymentCollectionRolloutScope: string
{
    case Disabled = 'disabled';
    case IndiaRazorpayOnly = 'india_razorpay_only';
    case CountryCapabilityRouting = 'country_capability_routing';

    public function label(): string
    {
        return match ($this) {
            self::Disabled => 'Disabled — no collection route resolves',
            self::IndiaRazorpayOnly => 'India / Razorpay only',
            self::CountryCapabilityRouting => 'Full country/currency capability routing',
        };
    }
}
