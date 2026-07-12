<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Phase 16B — RazorpayX payout settings baseline. Everything defaults
 * to off/empty; enabling the provider requires deliberate admin
 * configuration through RazorpayXPayoutSettingsPage, and even a fully
 * configured provider cannot execute a payout while
 * `instructor_earnings.payout_execution_enabled` stays false.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('razorpayx_payout.razorpayx_enabled', false);
        $this->migrator->add('razorpayx_payout.razorpayx_environment', 'test');
        $this->migrator->add('razorpayx_payout.razorpayx_key_id', null);
        $this->migrator->add('razorpayx_payout.razorpayx_key_secret', null);
        $this->migrator->add('razorpayx_payout.razorpayx_webhook_secret', null);
        $this->migrator->add('razorpayx_payout.razorpayx_previous_webhook_secret', null);
        $this->migrator->add('razorpayx_payout.razorpayx_account_number', null);
        $this->migrator->add('razorpayx_payout.razorpayx_default_mode', 'IMPS');
        $this->migrator->add('razorpayx_payout.razorpayx_default_purpose', 'payout');
        $this->migrator->add('razorpayx_payout.razorpayx_queue_if_low_balance', false);
        $this->migrator->add('razorpayx_payout.razorpayx_ip_allowlisting_confirmed_at', null);
        $this->migrator->add('razorpayx_payout.razorpayx_ip_allowlisting_confirmed_by', null);
        $this->migrator->add('razorpayx_payout.razorpayx_expected_outbound_ips', []);
        $this->migrator->add('razorpayx_payout.razorpayx_config_status', 'not_configured');
        $this->migrator->add('razorpayx_payout.razorpayx_last_checked_at', null);
        $this->migrator->add('razorpayx_payout.razorpayx_last_health_check_at', null);
        $this->migrator->add('razorpayx_payout.razorpayx_last_health_status', 'unknown');
        $this->migrator->add('razorpayx_payout.razorpayx_contact_provisioning_enabled', false);
        $this->migrator->add('razorpayx_payout.razorpayx_fund_account_provisioning_enabled', false);
    }
};
