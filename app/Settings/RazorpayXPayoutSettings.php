<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Phase 16B — RazorpayX-specific configuration, deliberately a
 * dedicated settings class rather than folded into
 * `InstructorEarningSettings` (too many fields, a different
 * confidentiality/rotation lifecycle) or `PaymentGatewaySettings` (a
 * different product — that class configures STUDENT COLLECTION
 * gateways; this one configures the INSTRUCTOR PAYOUT provider. Never
 * shared, never cross-read). Secrets are `Crypt::encryptString()`'d on
 * save (never re-displayed after initial submission — see
 * `RazorpayXPayoutSettingsPage::saveEncryptedField()`), decrypted only
 * by `RazorpayXHttpPayoutClient` and the webhook signature verifier.
 * `razorpayx_enabled` is a plain provider toggle — like
 * `PaymentGatewaySettings::razorpay_enabled`/`stripe_enabled` — not one
 * of the four canonical financial kill switches;
 * `InstructorEarningSettings::payout_execution_enabled` remains the
 * authoritative switch that must be on before this provider can ever
 * be called.
 */
class RazorpayXPayoutSettings extends Settings
{
    public bool $razorpayx_enabled;

    /** 'test' | 'live' — must match which key pair (test/live) is configured; validated structurally, not by a network call. */
    public string $razorpayx_environment;

    public ?string $razorpayx_key_id;

    /** Encrypted at rest; never returned to Livewire after initial submission. */
    public ?string $razorpayx_key_secret;

    /** Encrypted at rest. */
    public ?string $razorpayx_webhook_secret;

    /** Encrypted at rest; retained only for a controlled rotation window (§29). */
    public ?string $razorpayx_previous_webhook_secret;

    /** The RazorpayX business (source) account number payouts are debited from — sensitive operational configuration, never bank details. */
    public ?string $razorpayx_account_number;

    /** 'IMPS' | 'NEFT' | 'RTGS' */
    public string $razorpayx_default_mode;

    public string $razorpayx_default_purpose;

    /** Default OFF (§16) — insufficient balance must stay visible to finance, never silently queued indefinitely. */
    public bool $razorpayx_queue_if_low_balance;

    /**
     * Explicit, admin-confirmed operational control (§4) — never inferred
     * from a value merely being present. Payout readiness requires this
     * timestamp to be set.
     */
    public ?string $razorpayx_ip_allowlisting_confirmed_at;

    public ?int $razorpayx_ip_allowlisting_confirmed_by;

    /** @var list<string> */
    public array $razorpayx_expected_outbound_ips;

    /** 'not_configured' | 'incomplete' | 'invalid' | 'ready' — set only by RazorpayXPayoutConfigurationValidator, never by hand. */
    public string $razorpayx_config_status;

    public ?string $razorpayx_last_checked_at;

    public ?string $razorpayx_last_health_check_at;

    /** 'healthy' | 'unhealthy' | 'unknown' */
    public string $razorpayx_last_health_status;

    public bool $razorpayx_contact_provisioning_enabled;

    public bool $razorpayx_fund_account_provisioning_enabled;

    public static function group(): string
    {
        return 'razorpayx_payout';
    }
}
