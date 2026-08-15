<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Services\Payment\PaymentWebhookSignatureService;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * A gateway account can register several webhook endpoints, each
 * issued its own secret. This platform registers two Razorpay
 * endpoints — booking payments and package purchases — so verifying
 * against a single stored secret would reject every delivery from the
 * other endpoint as a forgery.
 */
class MultipleWebhookSecretsTest extends TestCase
{
    use RefreshDatabase;

    private function settingsWithSecrets(string $value): PaymentGatewaySettings
    {
        $settings = app(PaymentGatewaySettings::class);
        $settings->razorpay_webhook_secret = Crypt::encryptString($value);

        return $settings;
    }

    private function razorpayRequest(string $payload, string $secret): Request
    {
        $request = Request::create('/api/webhooks/packages/purchases/razorpay', 'POST', [], [], [], [], $payload);
        $request->headers->set('X-Razorpay-Signature', hash_hmac('sha256', $payload, $secret));

        return $request;
    }

    public function test_a_single_configured_secret_still_verifies(): void
    {
        $settings = $this->settingsWithSecrets('only_secret');

        $this->assertTrue(app(PaymentWebhookSignatureService::class)->isValid(
            'razorpay',
            $this->razorpayRequest('{"event":"payment.captured"}', 'only_secret'),
            $settings,
        ));
    }

    public function test_either_of_two_configured_secrets_verifies(): void
    {
        $settings = $this->settingsWithSecrets("booking_secret\npackage_secret");
        $service = app(PaymentWebhookSignatureService::class);
        $payload = '{"event":"payment.captured"}';

        // The booking endpoint's secret.
        $this->assertTrue($service->isValid('razorpay', $this->razorpayRequest($payload, 'booking_secret'), $settings));

        // The package endpoint's secret — this is the delivery that was
        // previously rejected as forged.
        $this->assertTrue($service->isValid('razorpay', $this->razorpayRequest($payload, 'package_secret'), $settings));
    }

    public function test_blank_lines_and_whitespace_are_ignored(): void
    {
        $settings = $this->settingsWithSecrets("  booking_secret  \n\n\n  package_secret\n");
        $service = app(PaymentWebhookSignatureService::class);
        $payload = '{"event":"payment.captured"}';

        $this->assertTrue($service->isValid('razorpay', $this->razorpayRequest($payload, 'booking_secret'), $settings));
        $this->assertTrue($service->isValid('razorpay', $this->razorpayRequest($payload, 'package_secret'), $settings));
    }

    /** Accepting several secrets must not weaken the actual check. */
    public function test_an_unknown_secret_is_still_rejected(): void
    {
        $settings = $this->settingsWithSecrets("booking_secret\npackage_secret");

        $this->assertFalse(app(PaymentWebhookSignatureService::class)->isValid(
            'razorpay',
            $this->razorpayRequest('{"event":"payment.captured"}', 'attacker_secret'),
            $settings,
        ));
    }

    public function test_a_tampered_payload_is_still_rejected(): void
    {
        $settings = $this->settingsWithSecrets("booking_secret\npackage_secret");

        $request = $this->razorpayRequest('{"event":"payment.captured"}', 'booking_secret');
        $tampered = Request::create('/api/webhooks/packages/purchases/razorpay', 'POST', [], [], [], [], '{"event":"payment.captured","amount":1}');
        $tampered->headers->set('X-Razorpay-Signature', (string) $request->header('X-Razorpay-Signature'));

        $this->assertFalse(app(PaymentWebhookSignatureService::class)->isValid('razorpay', $tampered, $settings));
    }

    /** A gateway with real verification must still fail closed when unconfigured. */
    // ── Endpoint isolation ────────────────────────────────────────────

    public function test_a_booking_scoped_secret_cannot_authenticate_the_package_endpoint(): void
    {
        $settings = $this->settingsWithSecrets("booking:booking_secret\npackage:package_secret");
        $service = app(PaymentWebhookSignatureService::class);
        $payload = '{"event":"payment.captured"}';

        // Each endpoint accepts its own secret...
        $this->assertTrue($service->isValid('razorpay', $this->razorpayRequest($payload, 'booking_secret'), $settings, PaymentWebhookSignatureService::PURPOSE_BOOKING));
        $this->assertTrue($service->isValid('razorpay', $this->razorpayRequest($payload, 'package_secret'), $settings, PaymentWebhookSignatureService::PURPOSE_PACKAGE));

        // ...and rejects the other's. A leak of one endpoint's secret
        // must never become authority over the other.
        $this->assertFalse($service->isValid('razorpay', $this->razorpayRequest($payload, 'booking_secret'), $settings, PaymentWebhookSignatureService::PURPOSE_PACKAGE));
        $this->assertFalse($service->isValid('razorpay', $this->razorpayRequest($payload, 'package_secret'), $settings, PaymentWebhookSignatureService::PURPOSE_BOOKING));
    }

    /** Rotation: two secrets for the SAME endpoint are both live. */
    public function test_rotating_a_booking_secret_keeps_both_live_without_leaking_to_package(): void
    {
        $settings = $this->settingsWithSecrets("booking:old_booking\nbooking:new_booking\npackage:package_secret");
        $service = app(PaymentWebhookSignatureService::class);
        $payload = '{"event":"payment.captured"}';

        $this->assertTrue($service->isValid('razorpay', $this->razorpayRequest($payload, 'old_booking'), $settings, PaymentWebhookSignatureService::PURPOSE_BOOKING));
        $this->assertTrue($service->isValid('razorpay', $this->razorpayRequest($payload, 'new_booking'), $settings, PaymentWebhookSignatureService::PURPOSE_BOOKING));

        // Neither rotation secret reaches the package endpoint.
        $this->assertFalse($service->isValid('razorpay', $this->razorpayRequest($payload, 'old_booking'), $settings, PaymentWebhookSignatureService::PURPOSE_PACKAGE));
        $this->assertFalse($service->isValid('razorpay', $this->razorpayRequest($payload, 'new_booking'), $settings, PaymentWebhookSignatureService::PURPOSE_PACKAGE));
    }

    /** Back-compat: an existing unprefixed secret keeps working everywhere. */
    public function test_an_unprefixed_legacy_secret_still_authenticates_every_endpoint(): void
    {
        $settings = $this->settingsWithSecrets('legacy_secret');
        $service = app(PaymentWebhookSignatureService::class);
        $payload = '{"event":"payment.captured"}';

        $this->assertTrue($service->isValid('razorpay', $this->razorpayRequest($payload, 'legacy_secret'), $settings, PaymentWebhookSignatureService::PURPOSE_BOOKING));
        $this->assertTrue($service->isValid('razorpay', $this->razorpayRequest($payload, 'legacy_secret'), $settings, PaymentWebhookSignatureService::PURPOSE_PACKAGE));
    }

    /** A secret containing a colon must not be truncated by prefix parsing. */
    public function test_a_secret_containing_a_colon_is_not_mistaken_for_a_scope(): void
    {
        $settings = $this->settingsWithSecrets('whsec:with:colons');

        $this->assertTrue(app(PaymentWebhookSignatureService::class)->isValid(
            'razorpay',
            $this->razorpayRequest('{"event":"payment.captured"}', 'whsec:with:colons'),
            $settings,
            PaymentWebhookSignatureService::PURPOSE_BOOKING,
        ));
    }

    public function test_no_configured_secret_still_fails_closed_for_razorpay(): void
    {
        $settings = app(PaymentGatewaySettings::class);
        $settings->razorpay_webhook_secret = null;

        $this->assertFalse(app(PaymentWebhookSignatureService::class)->isValid(
            'razorpay',
            $this->razorpayRequest('{"event":"payment.captured"}', 'anything'),
            $settings,
        ));
    }
}
