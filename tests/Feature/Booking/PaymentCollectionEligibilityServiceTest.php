<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\PaymentCollectionEligibilityServiceInterface;
use App\Booking\Enums\PaymentCollectionRolloutScope;
use App\Booking\Payments\RazorpayPaymentProvider;
use App\Models\Country;
use App\Models\Currency;
use App\Settings\BookingSettings;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * The collection market gate, in the order it actually decides:
 *
 *   payments_enabled → rollout scope → Country.status →
 *   provider routing → provider capability → provider health
 *
 * Every question is answered from canonical domain data. There is no
 * per-market branch anywhere in this service, which is why opening a
 * market is a country/pricing change rather than a code change.
 */
class PaymentCollectionEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_scope_is_active_country_routing(): void
    {
        $this->assertSame(
            PaymentCollectionRolloutScope::ActiveCountryRouting->value,
            app(PaymentGatewaySettings::class)->payment_collection_rollout_scope,
        );
    }

    public function test_the_rollout_enum_carries_no_per_market_mode(): void
    {
        // A single-country scope would put market policy in an enum,
        // where it drifts from the country data and needs a code change
        // per market. Markets live in Country.status/payment_routing.
        $this->assertSame(
            ['disabled', 'active_country_routing'],
            array_map(fn (PaymentCollectionRolloutScope $c): string => $c->value, PaymentCollectionRolloutScope::cases()),
        );
    }

    // ── Platform-wide gates ───────────────────────────────────────────────

    public function test_disabled_scope_blocks_every_country_including_india(): void
    {
        $this->market('IN', 'INR');
        $this->configureRazorpay(international: true, scope: PaymentCollectionRolloutScope::Disabled);

        $result = app(PaymentCollectionEligibilityServiceInterface::class)->resolve('IN', 'INR', 'booking_payment');

        $this->assertFalse($result->isEligible);
        $this->assertContains('collection_rollout_disabled', $result->blockingCodes);
        $this->assertNull($result->provider, 'A refused route must not name a provider.');
    }

    public function test_payments_enabled_false_blocks_every_country(): void
    {
        $this->market('IN', 'INR');
        $this->configureRazorpay(international: true);

        $gateways = app(PaymentGatewaySettings::class);
        $gateways->payments_enabled = false;
        $gateways->save();

        $result = app(PaymentCollectionEligibilityServiceInterface::class)->resolve('IN', 'INR', 'booking_payment');

        $this->assertFalse($result->isEligible);
        $this->assertContains('payment_collection_disabled', $result->blockingCodes);
    }

    // ── Market activation ─────────────────────────────────────────────────

    public function test_an_inactive_country_is_refused_even_when_priced_and_routed(): void
    {
        $this->market('FR', 'USD', active: false);
        $this->configureRazorpay(international: true);

        $result = app(PaymentCollectionEligibilityServiceInterface::class)->resolve('FR', 'USD', 'booking_payment');

        $this->assertFalse($result->isEligible);
        $this->assertContains('country_not_active', $result->blockingCodes);
        $this->assertNull($result->provider);
    }

    public function test_an_unknown_country_is_refused(): void
    {
        $this->configureRazorpay(international: true);

        $result = app(PaymentCollectionEligibilityServiceInterface::class)->resolve('ZZ', 'USD', 'booking_payment');

        $this->assertFalse($result->isEligible);
        $this->assertContains('country_not_active', $result->blockingCodes);
    }

    public function test_a_missing_country_is_refused(): void
    {
        $this->configureRazorpay(international: true);

        $result = app(PaymentCollectionEligibilityServiceInterface::class)->resolve(null, 'INR', 'booking_payment');

        $this->assertFalse($result->isEligible);
        $this->assertContains('country_not_active', $result->blockingCodes);
    }

    // ── Provider capability ───────────────────────────────────────────────

    public function test_india_inr_is_eligible_without_international_activation(): void
    {
        $this->market('IN', 'INR');
        $this->configureRazorpay(international: false);

        $result = app(PaymentCollectionEligibilityServiceInterface::class)->resolve('IN', 'INR', 'booking_payment');

        $this->assertTrue($result->isEligible, $result->summary());
        $this->assertSame('razorpay', $result->provider);
    }

    public function test_international_currencies_are_blocked_until_the_merchant_is_attested(): void
    {
        $this->configureRazorpay(international: false);

        foreach (RazorpayPaymentProvider::DEFAULT_INTERNATIONAL_CURRENCIES as $currency) {
            $this->market('US', $currency);

            $result = app(PaymentCollectionEligibilityServiceInterface::class)->resolve('US', $currency, 'booking_payment');

            $this->assertFalse($result->isEligible, "{$currency} must be blocked before attestation.");
            $this->assertContains('unsupported_billing_currency', $result->blockingCodes);
        }
    }

    public function test_attested_international_currencies_become_eligible(): void
    {
        $this->configureRazorpay(international: true);

        foreach (RazorpayPaymentProvider::DEFAULT_INTERNATIONAL_CURRENCIES as $currency) {
            $this->market('US', $currency);

            $result = app(PaymentCollectionEligibilityServiceInterface::class)->resolve('US', $currency, 'booking_payment');

            $this->assertTrue($result->isEligible, "{$currency}: ".$result->summary());
            $this->assertSame('razorpay', $result->provider);
        }
    }

    public function test_a_currency_outside_the_attested_set_stays_blocked(): void
    {
        // NZD/SAR are the real case: active market, valid route, but the
        // account has not been confirmed to collect the currency.
        $this->market('NZ', 'NZD');
        $this->configureRazorpay(international: true);

        $result = app(PaymentCollectionEligibilityServiceInterface::class)->resolve('NZ', 'NZD', 'booking_payment');

        $this->assertFalse($result->isEligible);
        $this->assertContains('unsupported_billing_currency', $result->blockingCodes);
    }

    public function test_attesting_one_currency_does_not_attest_the_rest(): void
    {
        $this->market('NZ', 'NZD');
        $this->configureRazorpay(international: true, currencies: ['USD']);

        $this->assertFalse(
            app(PaymentCollectionEligibilityServiceInterface::class)->resolve('NZ', 'NZD', 'booking_payment')->isEligible,
        );

        $this->market('US', 'USD');
        $this->assertTrue(
            app(PaymentCollectionEligibilityServiceInterface::class)->resolve('US', 'USD', 'booking_payment')->isEligible,
        );
    }

    public function test_inr_cannot_be_switched_off_by_editing_the_international_list(): void
    {
        $this->market('IN', 'INR');
        $this->configureRazorpay(international: true, currencies: []);

        $result = app(PaymentCollectionEligibilityServiceInterface::class)->resolve('IN', 'INR', 'booking_payment');

        $this->assertTrue($result->isEligible, 'Domestic collection must never depend on the international list.');
    }

    // ── Credentials ───────────────────────────────────────────────────────

    public function test_invalid_credentials_block_collection_without_naming_a_provider(): void
    {
        $this->market('IN', 'INR');
        $this->configureRazorpay(international: false);

        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_key_id = 'not-a-razorpay-key';
        $gateways->save();

        $result = app(PaymentCollectionEligibilityServiceInterface::class)->resolve('IN', 'INR', 'booking_payment');

        $this->assertFalse($result->isEligible);
        $this->assertContains('credentials_invalid', $result->blockingCodes);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function market(string $iso2, string $currencyCode, bool $active = true): void
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => $currencyCode],
            ['name' => $currencyCode, 'symbol' => $currencyCode, 'numeric_code' => (string) random_int(100, 999), 'minor_units' => 2, 'status' => 'active'],
        );

        Country::query()->updateOrCreate(
            ['iso2' => $iso2],
            [
                'name' => $iso2,
                'status' => $active ? 'active' : 'inactive',
                'default_currency_id' => $currency->id,
                'payment_routing' => ['provider' => 'razorpay', 'enabled' => true],
            ],
        );
    }

    /** @param list<string>|null $currencies */
    private function configureRazorpay(bool $international, ?array $currencies = null, ?PaymentCollectionRolloutScope $scope = null): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->payments_enabled = true;
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString('secret');
        $gateways->razorpay_international_enabled = $international;
        $gateways->razorpay_international_currencies = $currencies ?? RazorpayPaymentProvider::DEFAULT_INTERNATIONAL_CURRENCIES;
        $gateways->payment_collection_rollout_scope = ($scope ?? PaymentCollectionRolloutScope::ActiveCountryRouting)->value;
        $gateways->save();

        $bookings = app(BookingSettings::class);
        $bookings->payment_provider = 'razorpay';
        $bookings->save();
    }
}
