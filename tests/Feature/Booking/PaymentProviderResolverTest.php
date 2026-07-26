<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Exceptions\BookingException;
use App\Booking\Payments\FakePaymentProvider;
use App\Booking\Payments\RazorpayPaymentProvider;
use App\Booking\Payments\StripePaymentProvider;
use App\Booking\Registry\PaymentProviderRegistry;
use App\Booking\Services\PaymentProviderResolver;
use App\Models\Country;
use App\Settings\BookingSettings;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Tests\TestCase;

/**
 * The safety seam between "which provider is selected"
 * (BookingSettings::payment_provider) and "may it actually be used
 * right now" (PaymentProviderResolver). See
 * app/Booking/Services/PaymentProviderResolver.php for the two things
 * a raw registry lookup does not check.
 */
class PaymentProviderResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_returns_fake_provider_in_testing_environment(): void
    {
        app(BookingSettings::class)->payment_provider = 'fake';
        app(BookingSettings::class)->save();

        $provider = app(PaymentProviderResolver::class)->current();

        $this->assertInstanceOf(FakePaymentProvider::class, $provider);
    }

    public function test_resolver_returns_razorpay_when_configured(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString('secret');
        $gateways->save();

        app(BookingSettings::class)->payment_provider = 'razorpay';
        app(BookingSettings::class)->save();

        $provider = app(PaymentProviderResolver::class)->current();

        $this->assertInstanceOf(RazorpayPaymentProvider::class, $provider);
    }

    public function test_resolver_returns_stripe_when_configured(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->stripe_enabled = true;
        $gateways->stripe_publishable_key = 'pk_test_abc123';
        $gateways->stripe_secret_key = Crypt::encryptString('sk_test_abc123');
        $gateways->save();

        app(BookingSettings::class)->payment_provider = 'stripe';
        app(BookingSettings::class)->save();

        $provider = app(PaymentProviderResolver::class)->current();

        $this->assertInstanceOf(StripePaymentProvider::class, $provider);
    }

    public function test_resolver_rejects_disabled_razorpay(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = false;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString('secret');
        $gateways->save();

        app(BookingSettings::class)->payment_provider = 'razorpay';
        app(BookingSettings::class)->save();

        $this->expectException(BookingException::class);
        app(PaymentProviderResolver::class)->current();
    }

    public function test_resolver_rejects_razorpay_with_random_unformatted_key_id(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'totally-random-text-not-a-key';
        $gateways->razorpay_key_secret = Crypt::encryptString('secret');
        $gateways->save();

        app(BookingSettings::class)->payment_provider = 'razorpay';
        app(BookingSettings::class)->save();

        $this->expectException(BookingException::class);
        app(PaymentProviderResolver::class)->current();
    }

    public function test_resolver_rejects_stripe_missing_secret_key(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->stripe_enabled = true;
        $gateways->stripe_publishable_key = 'pk_test_abc123';
        $gateways->stripe_secret_key = null;
        $gateways->save();

        app(BookingSettings::class)->payment_provider = 'stripe';
        app(BookingSettings::class)->save();

        $this->expectException(BookingException::class);
        app(PaymentProviderResolver::class)->current();
    }

    public function test_resolver_rejects_unregistered_provider_key(): void
    {
        app(BookingSettings::class)->payment_provider = 'paypal';
        app(BookingSettings::class)->save();

        $this->expectException(BookingException::class);
        app(PaymentProviderResolver::class)->current();
    }

    public function test_production_does_not_silently_fall_back_to_fake(): void
    {
        $mockApp = Mockery::mock(Application::class);
        $mockApp->shouldReceive('environment')->with(['local', 'testing'])->andReturn(false);

        $resolver = new PaymentProviderResolver(
            app(PaymentProviderRegistry::class),
            app(BookingSettings::class),
            app(PaymentGatewaySettings::class),
            $mockApp,
        );

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/cannot be used outside local\/testing/');

        $resolver->resolve('fake');
    }

    public function test_fake_provider_is_still_usable_in_local_environment(): void
    {
        $mockApp = Mockery::mock(Application::class);
        $mockApp->shouldReceive('environment')->with(['local', 'testing'])->andReturn(true);

        $resolver = new PaymentProviderResolver(
            app(PaymentProviderRegistry::class),
            app(BookingSettings::class),
            app(PaymentGatewaySettings::class),
            $mockApp,
        );

        $this->assertInstanceOf(FakePaymentProvider::class, $resolver->resolve('fake'));
    }

    // ── Platform kill switch / allow-list / routing order ───────────────

    public function test_payments_enabled_false_blocks_every_provider_including_fake(): void
    {
        app(BookingSettings::class)->payment_provider = 'fake';
        app(BookingSettings::class)->save();

        $gateways = app(PaymentGatewaySettings::class);
        $gateways->payments_enabled = false;
        $gateways->save();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/disabled platform-wide/');
        app(PaymentProviderResolver::class)->current();
    }

    public function test_allowed_providers_restricts_resolution_even_when_configured(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString('secret');
        $gateways->allowed_providers = ['stripe'];
        $gateways->save();

        app(BookingSettings::class)->payment_provider = 'razorpay';
        app(BookingSettings::class)->save();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/not in the platform/');
        app(PaymentProviderResolver::class)->current();
    }

    public function test_default_provider_takes_priority_over_legacy_booking_setting(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString('secret');
        $gateways->default_provider = 'razorpay';
        $gateways->save();

        // Legacy knob still says fake — default_provider must win.
        app(BookingSettings::class)->payment_provider = 'fake';
        app(BookingSettings::class)->save();

        $provider = app(PaymentProviderResolver::class)->current();

        $this->assertInstanceOf(RazorpayPaymentProvider::class, $provider);
    }

    public function test_legacy_booking_setting_still_wins_when_default_provider_is_unset(): void
    {
        app(BookingSettings::class)->payment_provider = 'fake';
        app(BookingSettings::class)->save();

        // default_provider left null (its migrated default).
        $provider = app(PaymentProviderResolver::class)->current();

        $this->assertInstanceOf(FakePaymentProvider::class, $provider);
    }

    public function test_country_routing_takes_priority_over_default_provider(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString('secret');
        $gateways->stripe_enabled = true;
        $gateways->stripe_publishable_key = 'pk_test_abc123';
        $gateways->stripe_secret_key = Crypt::encryptString('sk_test_abc123');
        $gateways->default_provider = 'razorpay';
        $gateways->save();

        Country::factory()->create(['iso2' => 'US', 'payment_routing' => ['provider' => 'stripe', 'enabled' => true]]);

        $provider = app(PaymentProviderResolver::class)->current('US');

        $this->assertInstanceOf(StripePaymentProvider::class, $provider);
    }

    public function test_country_routing_disabled_falls_through_to_default_provider(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString('secret');
        $gateways->default_provider = 'razorpay';
        $gateways->save();

        Country::factory()->create(['iso2' => 'GB', 'payment_routing' => ['provider' => 'stripe', 'enabled' => false]]);

        $provider = app(PaymentProviderResolver::class)->current('GB');

        $this->assertInstanceOf(RazorpayPaymentProvider::class, $provider);
    }

    public function test_country_with_no_routing_falls_through_to_default_provider(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString('secret');
        $gateways->default_provider = 'razorpay';
        $gateways->save();

        Country::factory()->create(['iso2' => 'IN', 'payment_routing' => null]);

        $provider = app(PaymentProviderResolver::class)->current('IN');

        $this->assertInstanceOf(RazorpayPaymentProvider::class, $provider);
    }
}
