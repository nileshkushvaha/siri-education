<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Rollout POLICY, not a kill switch.
 * `PaymentGatewaySettings::payments_enabled` remains the sole
 * authoritative switch. Defaults to `india_razorpay_only`, matching
 * the platform's current safe state (Razorpay is the only provider
 * with a verified, tested end-to-end checkout flow today).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('payment_gateways.payment_collection_rollout_scope', 'india_razorpay_only');
    }
};
