<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\PaymentCollectionEligibilityServiceInterface;
use App\Booking\Enums\PaymentCollectionRolloutScope;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Country routing / rollout-scope coverage the Phase 16C testing
 * checklist asks for. Confirms the platform's actual, current safe
 * state (india_razorpay_only, Stripe unroutable outside isolated tests)
 * is enforced by this read-only preview, not just documented.
 */
class PaymentCollectionEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_scope_is_india_razorpay_only(): void
    {
        $this->assertSame(
            PaymentCollectionRolloutScope::IndiaRazorpayOnly->value,
            app(PaymentGatewaySettings::class)->payment_collection_rollout_scope,
        );
    }

    public function test_india_razorpay_only_scope_blocks_a_non_indian_student_regardless_of_currency(): void
    {
        $result = app(PaymentCollectionEligibilityServiceInterface::class)
            ->resolve('US', 'USD', 'booking_payment');

        $this->assertFalse($result->isEligible);
        $this->assertContains('country_route_missing', $result->blockingCodes);
        // Never leaks which provider would have been used, since none was resolved.
        $this->assertNull($result->provider);
    }

    public function test_disabled_scope_blocks_every_country_including_india(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->payment_collection_rollout_scope = PaymentCollectionRolloutScope::Disabled->value;
        $gateways->save();

        $result = app(PaymentCollectionEligibilityServiceInterface::class)
            ->resolve('IN', 'INR', 'booking_payment');

        $this->assertFalse($result->isEligible);
        $this->assertContains('country_route_missing', $result->blockingCodes);
    }

    public function test_payments_enabled_false_blocks_every_country_even_under_india_razorpay_only(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->payments_enabled = false;
        $gateways->save();

        $result = app(PaymentCollectionEligibilityServiceInterface::class)
            ->resolve('IN', 'INR', 'booking_payment');

        $this->assertFalse($result->isEligible);
        $this->assertContains('payment_collection_disabled', $result->blockingCodes);
    }

    public function test_stripe_currencies_remain_unroutable_under_the_current_safe_default(): void
    {
        // Confirms the platform-wide guardrail: no country currently has
        // Stripe configured in payment_routing, and the rollout scope
        // stays india_razorpay_only — a USD/GBP/EUR/AED student finds no
        // route today, which is the deliberately safe starting state for
        // this phase (international collection is not being activated).
        foreach (['USD', 'GBP', 'EUR', 'AED'] as $currency) {
            $result = app(PaymentCollectionEligibilityServiceInterface::class)
                ->resolve('GB', $currency, 'booking_payment');

            $this->assertFalse($result->isEligible, "{$currency} must remain unroutable under the current safe default.");
        }
    }
}
