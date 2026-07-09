<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\DTOs\PaymentGatewayReadiness;
use App\Booking\Payments\FakePaymentProvider;
use App\Services\Payment\PaymentWebhookSignatureService;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Support\Carbon;

/**
 * Determines and persists each provider's admin-facing readiness
 * status (`not_configured`/`incomplete`/`invalid`/`ready`). Never
 * calls the gateway — this is pure settings/format inspection, the
 * same rule PaymentProviderConfigValidator follows, so this service's
 * "Validate Configuration" action is safe to run in automated tests
 * and does not require network access or stubbed HTTP responses.
 *
 * Distinct from PaymentProviderResolver: the resolver makes a
 * real-time, unpersisted decision at the moment a payment is
 * initiated (isConfigured() is re-evaluated every call, never cached);
 * this service is the admin-facing "click Validate Configuration and
 * see a status badge" bookkeeping layer, persisted so the admin UI can
 * render a status without re-deriving it on every page load.
 */
final class PaymentGatewayConfigurationService
{
    public function __construct(
        private readonly PaymentGatewaySettings $settings,
        private readonly PaymentProviderConfigValidator $validator,
    ) {}

    public function checkFake(): PaymentGatewayReadiness
    {
        // Never "ready" in the same sense as a real gateway — it moves no
        // money — but always structurally valid; PaymentProviderResolver
        // is what actually blocks it outside local/testing, not this status.
        return new PaymentGatewayReadiness(FakePaymentProvider::KEY, 'ready');
    }

    public function checkRazorpay(): PaymentGatewayReadiness
    {
        $issues = [];

        if (! $this->settings->razorpay_enabled) {
            return $this->persist('razorpay', 'not_configured', ['Razorpay is not enabled.']);
        }

        if (blank($this->settings->razorpay_key_id) || blank($this->settings->razorpay_key_secret)) {
            $issues[] = 'Razorpay key_id or key_secret is missing.';
        } elseif (! $this->validator->isValidRazorpayKeyId($this->settings->razorpay_key_id)) {
            return $this->persist('razorpay', 'invalid', ['Razorpay key_id does not match the expected rzp_(test|live)_... format.']);
        }

        if (blank($this->settings->razorpay_webhook_secret)) {
            $issues[] = 'Razorpay webhook_secret is missing — webhooks cannot be verified.';
        }

        if ($issues !== []) {
            return $this->persist('razorpay', 'incomplete', $issues);
        }

        return $this->persist('razorpay', 'ready');
    }

    public function checkStripe(): PaymentGatewayReadiness
    {
        $issues = [];

        if (! $this->settings->stripe_enabled) {
            return $this->persist('stripe', 'not_configured', ['Stripe is not enabled.']);
        }

        $secretKey = PaymentWebhookSignatureService::decryptSecret($this->settings, 'stripe_secret_key');

        if (blank($secretKey) || blank($this->settings->stripe_publishable_key)) {
            $issues[] = 'Stripe secret_key or publishable_key is missing.';
        } else {
            if (! $this->validator->isValidStripeSecretKey($secretKey)) {
                return $this->persist('stripe', 'invalid', ['Stripe secret_key does not match the expected sk_(test|live)_... format.']);
            }

            if (! $this->validator->isValidStripePublishableKey($this->settings->stripe_publishable_key)) {
                return $this->persist('stripe', 'invalid', ['Stripe publishable_key does not match the expected pk_(test|live)_... format.']);
            }
        }

        if (blank($this->settings->stripe_webhook_secret)) {
            $issues[] = 'Stripe webhook_secret is missing — webhooks cannot be verified.';
        }

        if ($issues !== []) {
            return $this->persist('stripe', 'incomplete', $issues);
        }

        return $this->persist('stripe', 'ready');
    }

    /** @param  list<string>  $issues */
    private function persist(string $provider, string $status, array $issues = []): PaymentGatewayReadiness
    {
        $statusField = "{$provider}_config_status";
        $checkedField = "{$provider}_last_checked_at";

        $this->settings->{$statusField} = $status;
        $this->settings->{$checkedField} = Carbon::now()->toIso8601String();
        $this->settings->save();

        return new PaymentGatewayReadiness($provider, $status, $issues);
    }
}
