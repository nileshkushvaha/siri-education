<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('compliance_monitoring.repeated_failed_logins_enabled', true);
        $this->migrator->add('compliance_monitoring.repeated_failed_logins_threshold', 5);
        $this->migrator->add('compliance_monitoring.repeated_failed_logins_window_minutes', 30);
        $this->migrator->add('compliance_monitoring.repeated_failed_logins_severity', 'high');
        $this->migrator->add('compliance_monitoring.repeated_failed_logins_cooldown_minutes', 60);

        $this->migrator->add('compliance_monitoring.excessive_booking_cancellations_enabled', true);
        $this->migrator->add('compliance_monitoring.excessive_booking_cancellations_threshold', 3);
        $this->migrator->add('compliance_monitoring.excessive_booking_cancellations_window_days', 7);
        $this->migrator->add('compliance_monitoring.excessive_booking_cancellations_severity', 'medium');
        $this->migrator->add('compliance_monitoring.excessive_booking_cancellations_cooldown_minutes', 1440);

        $this->migrator->add('compliance_monitoring.repeated_referral_fraud_holds_enabled', true);
        $this->migrator->add('compliance_monitoring.repeated_referral_fraud_holds_threshold', 3);
        $this->migrator->add('compliance_monitoring.repeated_referral_fraud_holds_window_days', 30);
        $this->migrator->add('compliance_monitoring.repeated_referral_fraud_holds_severity', 'high');
        $this->migrator->add('compliance_monitoring.repeated_referral_fraud_holds_cooldown_minutes', 1440);

        $this->migrator->add('compliance_monitoring.unusual_manual_wallet_adjustments_enabled', true);
        $this->migrator->add('compliance_monitoring.unusual_manual_wallet_adjustments_threshold', 3);
        $this->migrator->add('compliance_monitoring.unusual_manual_wallet_adjustments_window_hours', 24);
        $this->migrator->add('compliance_monitoring.unusual_manual_wallet_adjustments_severity', 'critical');
        $this->migrator->add('compliance_monitoring.unusual_manual_wallet_adjustments_cooldown_minutes', 1440);
    }
};
