<?php

declare(strict_types=1);

namespace App\Compliance\Enums;

/**
 * One case per deterministic rule (GAP-014/GAP-015). `category()` is
 * the single source of truth linking a rule to its domain — the
 * flag's own `category` column is always derived from this, never set
 * independently, so a rule and its category can never drift apart.
 */
enum SuspiciousActivityRuleCode: string
{
    case RepeatedFailedLogins = 'repeated_failed_logins';
    case ExcessiveBookingCancellations = 'excessive_booking_cancellations';
    case RepeatedReferralFraudHolds = 'repeated_referral_fraud_holds';
    case UnusualManualWalletAdjustments = 'unusual_manual_wallet_adjustments';
    case RepeatedMessageReports = 'repeated_message_reports';

    public function label(): string
    {
        return match ($this) {
            self::RepeatedFailedLogins => 'Repeated Failed Logins',
            self::ExcessiveBookingCancellations => 'Excessive Booking Cancellations',
            self::RepeatedReferralFraudHolds => 'Repeated Referral Fraud Holds',
            self::UnusualManualWalletAdjustments => 'Unusual Manual Wallet Adjustments',
            self::RepeatedMessageReports => 'Repeated Message Reports',
        };
    }

    public function category(): SuspiciousActivityCategory
    {
        return match ($this) {
            self::RepeatedFailedLogins => SuspiciousActivityCategory::Auth,
            self::ExcessiveBookingCancellations => SuspiciousActivityCategory::Booking,
            self::RepeatedReferralFraudHolds => SuspiciousActivityCategory::Referral,
            self::UnusualManualWalletAdjustments => SuspiciousActivityCategory::Wallet,
            self::RepeatedMessageReports => SuspiciousActivityCategory::Messaging,
        };
    }
}
