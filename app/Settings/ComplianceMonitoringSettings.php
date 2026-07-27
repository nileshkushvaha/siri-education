<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Typed settings for the rule-based compliance monitoring system.
 * Each of the four rules gets its own
 * enabled/threshold/observation-window/severity/cooldown group —
 * `severity` is stored as the raw string value of
 * SuspiciousActivityFlagSeverity so it can be validated with
 * `::from()` at evaluation time. The observation window bounds how
 * far back a rule counts qualifying events; the cooldown window is
 * unrelated to that count — it is how long after a flag for the same
 * fingerprint is resolved/dismissed before a new flag may be created
 * for it again, preventing immediate re-flagging churn right after an
 * administrator has already made a decision.
 */
class ComplianceMonitoringSettings extends Settings
{
    public bool $repeated_failed_logins_enabled;

    public int $repeated_failed_logins_threshold;

    public int $repeated_failed_logins_window_minutes;

    public string $repeated_failed_logins_severity;

    public int $repeated_failed_logins_cooldown_minutes;

    public bool $excessive_booking_cancellations_enabled;

    public int $excessive_booking_cancellations_threshold;

    public int $excessive_booking_cancellations_window_days;

    public string $excessive_booking_cancellations_severity;

    public int $excessive_booking_cancellations_cooldown_minutes;

    public bool $repeated_referral_fraud_holds_enabled;

    public int $repeated_referral_fraud_holds_threshold;

    public int $repeated_referral_fraud_holds_window_days;

    public string $repeated_referral_fraud_holds_severity;

    public int $repeated_referral_fraud_holds_cooldown_minutes;

    public bool $unusual_manual_wallet_adjustments_enabled;

    public int $unusual_manual_wallet_adjustments_threshold;

    public int $unusual_manual_wallet_adjustments_window_hours;

    public string $unusual_manual_wallet_adjustments_severity;

    public int $unusual_manual_wallet_adjustments_cooldown_minutes;

    public bool $repeated_message_reports_enabled;

    public int $repeated_message_reports_threshold;

    public int $repeated_message_reports_window_days;

    public string $repeated_message_reports_severity;

    public int $repeated_message_reports_cooldown_minutes;

    public static function group(): string
    {
        return 'compliance_monitoring';
    }
}
