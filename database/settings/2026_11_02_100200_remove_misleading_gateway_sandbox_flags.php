<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Removes the per-gateway "Sandbox Mode" flags.
 *
 * They looked like a safety control and were not one. Nothing read
 * them except a dashboard label — no key was swapped, no endpoint was
 * changed — so an operator could set Sandbox Mode ON while an
 * `rzp_live_` key talked to production Razorpay and charged real
 * cards. The inverse was equally possible: a test key labelled "live".
 *
 * Live-vs-test is now derived from the provider's own key prefix
 * (PaymentGatewaySettings::razorpayIsLive()/stripeIsLive()), which is
 * the only value that cannot contradict what the provider actually
 * does.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->deleteIfExists('payment_gateways.razorpay_sandbox_mode');
        $this->migrator->deleteIfExists('payment_gateways.stripe_sandbox_mode');
        $this->migrator->deleteIfExists('payment_gateways.applepay_sandbox_mode');
    }

    public function down(): void
    {
        $this->migrator->add('payment_gateways.razorpay_sandbox_mode', true);
        $this->migrator->add('payment_gateways.stripe_sandbox_mode', true);
        $this->migrator->add('payment_gateways.applepay_sandbox_mode', true);
    }
};
