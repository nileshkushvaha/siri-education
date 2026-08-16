<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * `razorpay_international_enabled` — an operator's attestation that
 * Razorpay has APPROVED International Payments on the merchant account.
 *
 * Defaults to FALSE, so deploying this enables nothing on its own:
 * international collection stays closed until someone confirms the
 * Dashboard state. Live credentials are NOT evidence of approval — a
 * domestic-only account holds identical-looking `rzp_live_*` keys and
 * will accept a foreign-currency order, then decline it at capture,
 * after the student has entered card details.
 *
 * The rollout scope and the per-account currency list are handled by
 * 2026_11_05_100000, which owns the multi-country activation.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('payment_gateways.razorpay_international_enabled', false);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('payment_gateways.razorpay_international_enabled');
    }
};
