<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Additive readiness/routing bookkeeping fields.
 * default_provider stays null so BookingSettings::payment_provider
 * (already load-bearing for every existing checkout flow) remains
 * authoritative until an admin deliberately sets this one.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('payment_gateways.default_provider', null);
        $this->migrator->add('payment_gateways.payments_enabled', true);
        $this->migrator->add('payment_gateways.allow_guest_checkout', true);
        $this->migrator->add('payment_gateways.allow_student_checkout', true);
        $this->migrator->add('payment_gateways.allowed_providers', []);
        $this->migrator->add('payment_gateways.production_ready_at', null);
        $this->migrator->add('payment_gateways.last_configuration_checked_at', null);
        $this->migrator->add('payment_gateways.fake_enabled', true);

        $this->migrator->add('payment_gateways.razorpay_config_status', 'not_configured');
        $this->migrator->add('payment_gateways.razorpay_last_checked_at', null);
        $this->migrator->add('payment_gateways.razorpay_supported_currencies', ['INR']);

        $this->migrator->add('payment_gateways.stripe_config_status', 'not_configured');
        $this->migrator->add('payment_gateways.stripe_last_checked_at', null);
        $this->migrator->add('payment_gateways.stripe_supported_currencies', ['USD', 'GBP', 'EUR', 'AED']);
    }
};
