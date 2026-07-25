<?php

declare(strict_types=1);

namespace App\Compliance\Support;

use App\Compliance\Enums\SuspiciousActivityRuleCode;
use App\Settings\ComplianceMonitoringSettings;

/**
 * Captures the exact rule configuration in force at evaluation time
 * onto the flag row itself — a later settings change (e.g. raising a
 * threshold) never retroactively reinterprets an already-recorded
 * flag. Mirrors QualityAlertThresholdSnapshot's identical role for
 * quality_alerts.threshold_snapshot.
 */
final class ComplianceThresholdSnapshot
{
    /** @return array<string, int|string|bool> */
    public static function capture(SuspiciousActivityRuleCode $rule, ComplianceMonitoringSettings $settings): array
    {
        return match ($rule) {
            SuspiciousActivityRuleCode::RepeatedFailedLogins => [
                'enabled' => $settings->repeated_failed_logins_enabled,
                'threshold' => $settings->repeated_failed_logins_threshold,
                'window_minutes' => $settings->repeated_failed_logins_window_minutes,
                'severity' => $settings->repeated_failed_logins_severity,
                'cooldown_minutes' => $settings->repeated_failed_logins_cooldown_minutes,
            ],
            SuspiciousActivityRuleCode::ExcessiveBookingCancellations => [
                'enabled' => $settings->excessive_booking_cancellations_enabled,
                'threshold' => $settings->excessive_booking_cancellations_threshold,
                'window_days' => $settings->excessive_booking_cancellations_window_days,
                'severity' => $settings->excessive_booking_cancellations_severity,
                'cooldown_minutes' => $settings->excessive_booking_cancellations_cooldown_minutes,
            ],
            SuspiciousActivityRuleCode::RepeatedReferralFraudHolds => [
                'enabled' => $settings->repeated_referral_fraud_holds_enabled,
                'threshold' => $settings->repeated_referral_fraud_holds_threshold,
                'window_days' => $settings->repeated_referral_fraud_holds_window_days,
                'severity' => $settings->repeated_referral_fraud_holds_severity,
                'cooldown_minutes' => $settings->repeated_referral_fraud_holds_cooldown_minutes,
            ],
            SuspiciousActivityRuleCode::UnusualManualWalletAdjustments => [
                'enabled' => $settings->unusual_manual_wallet_adjustments_enabled,
                'threshold' => $settings->unusual_manual_wallet_adjustments_threshold,
                'window_hours' => $settings->unusual_manual_wallet_adjustments_window_hours,
                'severity' => $settings->unusual_manual_wallet_adjustments_severity,
                'cooldown_minutes' => $settings->unusual_manual_wallet_adjustments_cooldown_minutes,
            ],
        };
    }
}
