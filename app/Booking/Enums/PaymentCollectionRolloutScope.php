<?php

declare(strict_types=1);

namespace App\Booking\Enums;

/**
 * Rollout POLICY, not a kill switch — `PaymentGatewaySettings::payments_enabled`
 * remains the authoritative switch.
 *
 * AUTHORITATIVE, not advisory. Both real checkout paths —
 * BookingPaymentService::initiate() and
 * PackagePurchaseService::startCheckout() — resolve through
 * PaymentCollectionEligibilityService, so this setting gates money
 * movement rather than only shaping a preview.
 *
 * There is deliberately no per-market mode. A single-country scope
 * (the retired `india_razorpay_only`) encoded market policy in an enum,
 * which meant every new market needed a code change and could drift from
 * the country data. Markets are now opened and closed entirely through
 * `Country.status` and `Country.payment_routing`, so this enum answers
 * one question only: is collection running at all?
 */
enum PaymentCollectionRolloutScope: string
{
    /** Nothing collects, anywhere — a deliberate full stop. */
    case Disabled = 'disabled';

    /** Active, explicitly-routed countries collect; everything else is refused. */
    case ActiveCountryRouting = 'active_country_routing';

    public function label(): string
    {
        return match ($this) {
            self::Disabled => 'Disabled — no collection route resolves',
            self::ActiveCountryRouting => 'Active countries with explicit provider routing',
        };
    }
}
