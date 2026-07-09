<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Services\PaymentGatewayConfigurationService;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Phase 10.2A — admin-facing readiness bookkeeping. Never calls a
 * gateway (format/settings inspection only), so every test here runs
 * with no HTTP faking and no mocked SDK client.
 */
class PaymentGatewayConfigurationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fake_provider_is_always_ready(): void
    {
        $result = app(PaymentGatewayConfigurationService::class)->checkFake();

        $this->assertTrue($result->isReady());
        $this->assertSame('fake', $result->provider);
    }

    public function test_razorpay_not_configured_when_disabled(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = false;
        $gateways->save();

        $result = app(PaymentGatewayConfigurationService::class)->checkRazorpay();

        $this->assertSame('not_configured', $result->status);
        $this->assertSame('not_configured', app(PaymentGatewaySettings::class)->razorpay_config_status);
    }

    public function test_razorpay_incomplete_when_webhook_secret_missing(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString('secret');
        $gateways->razorpay_webhook_secret = null;
        $gateways->save();

        $result = app(PaymentGatewayConfigurationService::class)->checkRazorpay();

        $this->assertSame('incomplete', $result->status);
        $this->assertNotEmpty($result->issues);
    }

    public function test_razorpay_invalid_when_key_id_is_random_text(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'totally-random-text';
        $gateways->razorpay_key_secret = Crypt::encryptString('secret');
        $gateways->razorpay_webhook_secret = Crypt::encryptString('whsecret');
        $gateways->save();

        $result = app(PaymentGatewayConfigurationService::class)->checkRazorpay();

        $this->assertSame('invalid', $result->status);
    }

    public function test_razorpay_ready_when_fully_configured(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString('secret');
        $gateways->razorpay_webhook_secret = Crypt::encryptString('whsecret');
        $gateways->save();

        $result = app(PaymentGatewayConfigurationService::class)->checkRazorpay();

        $this->assertTrue($result->isReady());
        $this->assertSame([], $result->issues);
        $this->assertNotNull(app(PaymentGatewaySettings::class)->razorpay_last_checked_at);
    }

    public function test_stripe_not_configured_when_disabled(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->stripe_enabled = false;
        $gateways->save();

        $result = app(PaymentGatewayConfigurationService::class)->checkStripe();

        $this->assertSame('not_configured', $result->status);
    }

    public function test_stripe_incomplete_when_webhook_secret_missing(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->stripe_enabled = true;
        $gateways->stripe_publishable_key = 'pk_test_abc123';
        $gateways->stripe_secret_key = Crypt::encryptString('sk_test_abc123');
        $gateways->stripe_webhook_secret = null;
        $gateways->save();

        $result = app(PaymentGatewayConfigurationService::class)->checkStripe();

        $this->assertSame('incomplete', $result->status);
    }

    public function test_stripe_invalid_when_secret_key_is_random_text(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->stripe_enabled = true;
        $gateways->stripe_publishable_key = 'pk_test_abc123';
        $gateways->stripe_secret_key = Crypt::encryptString('totally-random-text');
        $gateways->stripe_webhook_secret = Crypt::encryptString('whsec_abc');
        $gateways->save();

        $result = app(PaymentGatewayConfigurationService::class)->checkStripe();

        $this->assertSame('invalid', $result->status);
    }

    public function test_stripe_ready_when_fully_configured(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->stripe_enabled = true;
        $gateways->stripe_publishable_key = 'pk_test_abc123';
        $gateways->stripe_secret_key = Crypt::encryptString('sk_test_abc123');
        $gateways->stripe_webhook_secret = Crypt::encryptString('whsec_abc123');
        $gateways->save();

        $result = app(PaymentGatewayConfigurationService::class)->checkStripe();

        $this->assertTrue($result->isReady());
    }

    public function test_config_status_never_calls_the_gateway_network(): void
    {
        // No Http::fake()/mocked SDK client bound anywhere in this test —
        // if this service made a real network call, it would either hang
        // or fail against api.razorpay.com/api.stripe.com in CI, proving
        // by construction that it doesn't.
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString('secret');
        $gateways->razorpay_webhook_secret = Crypt::encryptString('whsecret');
        $gateways->save();

        $result = app(PaymentGatewayConfigurationService::class)->checkRazorpay();

        $this->assertTrue($result->isReady());
    }
}
