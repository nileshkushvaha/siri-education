<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class PaymentGatewaySettings extends Settings
{
    /**
     * Live-vs-test is NOT a stored flag.
     *
     * There used to be a `*_sandbox_mode` toggle per gateway. It was
     * read by exactly one dashboard label and changed no API behaviour
     * whatsoever — so an admin could set "Sandbox Mode" ON while a
     * `rzp_live_` key talked to production Razorpay and charged real
     * cards. A switch that looks like a safety control but isn't is
     * worse than no switch at all.
     *
     * The provider's own key prefix is the single source of truth, and
     * it cannot disagree with itself.
     */
    public function razorpayIsLive(): bool
    {
        return str_starts_with((string) $this->razorpay_key_id, 'rzp_live_');
    }

    public function stripeIsLive(): bool
    {
        return str_starts_with((string) $this->stripe_publishable_key, 'pk_live_')
            || str_starts_with((string) $this->stripe_secret_key, 'sk_live_');
    }

    // Platform-wide gateway routing/kill-switch. None of
    // these are read by PaymentProviderResolver unless explicitly set —
    // see PaymentProviderResolver's routing-order docblock. Adding these
    // does not change existing behavior: default_provider starts null,
    // so BookingSettings::payment_provider (the pre-existing, already
    // load-bearing selection knob) remains authoritative until an admin
    // deliberately sets one.
    public ?string $default_provider;

    public bool $payments_enabled;

    public bool $allow_guest_checkout;

    public bool $allow_student_checkout;

    /** @var list<string> Provider keys permitted platform-wide; empty = no platform-level restriction. */
    public array $allowed_providers;

    public ?string $production_ready_at;

    public ?string $last_configuration_checked_at;

    public bool $fake_enabled;

    /** Rollout policy (not a kill switch); see App\Booking\Enums\PaymentCollectionRolloutScope. */
    public string $payment_collection_rollout_scope;

    /** Gates BookingPaymentReconciliationService::reconcileDue(); detection-only, moves no money. */
    public bool $booking_payment_reconciliation_enabled;

    /** Minutes a non-terminal payment must sit unsynced before a reconciliation sweep polls it again. */
    public int $booking_payment_unknown_timeout_minutes;

    // Per-provider readiness bookkeeping, set by PaymentGatewayConfigurationService
    // — never by hand. Distinct from *_enabled (an admin's on/off switch):
    // *_config_status reflects whether credentials actually pass validation.
    public string $razorpay_config_status;

    public ?string $razorpay_last_checked_at;

    /** @var list<string> */
    public array $razorpay_supported_currencies;

    public string $stripe_config_status;

    public ?string $stripe_last_checked_at;

    /** @var list<string> */
    public array $stripe_supported_currencies;

    public bool $stripe_enabled;

    public ?string $stripe_publishable_key;

    public ?string $stripe_secret_key;

    public ?string $stripe_webhook_secret;

    public ?string $stripe_success_url;

    public ?string $stripe_failure_url;

    public ?string $stripe_webhook_url;

    public bool $razorpay_enabled;

    public ?string $razorpay_key_id;

    public ?string $razorpay_key_secret;

    public ?string $razorpay_webhook_secret;

    public ?string $razorpay_success_url;

    public ?string $razorpay_failure_url;

    public ?string $razorpay_webhook_url;

    public bool $paypal_enabled;

    public string $paypal_mode;

    public ?string $paypal_client_id;

    public ?string $paypal_client_secret;

    public ?string $paypal_webhook_secret;

    public ?string $paypal_success_url;

    public ?string $paypal_failure_url;

    public ?string $paypal_webhook_url;

    // Apple Pay is presented as its own gateway in the admin so its
    // merchant identity and domain verification can be managed
    // separately, even though settlement rides on an existing
    // processor. `merchant_id` and `domain` are public-facing values;
    // the certificate/key material is secret and stored encrypted like
    // every other gateway secret on this model.
    public bool $applepay_enabled;

    public ?string $applepay_merchant_id;

    public ?string $applepay_merchant_domain;

    public ?string $applepay_merchant_certificate;

    public ?string $applepay_merchant_key;

    public ?string $applepay_webhook_secret;

    public ?string $applepay_success_url;

    public ?string $applepay_failure_url;

    public ?string $applepay_webhook_url;

    public bool $manual_enabled;

    public ?string $manual_payment_instructions;

    public static function group(): string
    {
        return 'payment_gateways';
    }
}
