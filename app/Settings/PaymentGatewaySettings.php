<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class PaymentGatewaySettings extends Settings
{
    // Platform-wide gateway routing/kill-switch (Phase 10.2A). None of
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

    /** Phase 16A.1 — rollout policy (not a kill switch); see App\Booking\Enums\PaymentCollectionRolloutScope. */
    public string $payment_collection_rollout_scope;

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

    public bool $stripe_sandbox_mode;

    public ?string $stripe_publishable_key;

    public ?string $stripe_secret_key;

    public ?string $stripe_webhook_secret;

    public ?string $stripe_success_url;

    public ?string $stripe_failure_url;

    public ?string $stripe_webhook_url;

    public bool $razorpay_enabled;

    public bool $razorpay_sandbox_mode;

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

    public bool $cashfree_enabled;

    public string $cashfree_environment;

    public ?string $cashfree_app_id;

    public ?string $cashfree_secret_key;

    public ?string $cashfree_webhook_secret;

    public ?string $cashfree_success_url;

    public ?string $cashfree_failure_url;

    public ?string $cashfree_webhook_url;

    public bool $payu_enabled;

    public bool $payu_sandbox_mode;

    public ?string $payu_merchant_id;

    public ?string $payu_public_key;

    public ?string $payu_private_key;

    public ?string $payu_webhook_secret;

    public ?string $payu_success_url;

    public ?string $payu_failure_url;

    public ?string $payu_webhook_url;

    public bool $phonepe_enabled;

    public bool $phonepe_sandbox_mode;

    public ?string $phonepe_merchant_id;

    public ?string $phonepe_salt_key;

    public ?string $phonepe_salt_index;

    public ?string $phonepe_webhook_secret;

    public ?string $phonepe_success_url;

    public ?string $phonepe_failure_url;

    public ?string $phonepe_webhook_url;

    public bool $manual_enabled;

    public ?string $manual_payment_instructions;

    public static function group(): string
    {
        return 'payment_gateways';
    }
}
