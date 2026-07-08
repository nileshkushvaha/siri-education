<?php

declare(strict_types=1);

namespace App\Wallet\Enums;

enum WalletLedgerEntryType: string
{
    case RechargePending = 'recharge_pending';
    case RechargeConfirmed = 'recharge_confirmed';
    case BookingPayment = 'booking_payment';
    case BookingHold = 'booking_hold';
    case BookingHoldRelease = 'booking_hold_release';
    case Refund = 'refund';
    case ReferralCredit = 'referral_credit';
    case PromotionalCredit = 'promotional_credit';
    case AdminAdjustment = 'admin_adjustment';
    case Expiry = 'expiry';

    public function label(): string
    {
        return match ($this) {
            self::RechargePending => 'Recharge Pending',
            self::RechargeConfirmed => 'Recharge Confirmed',
            self::BookingPayment => 'Booking Payment',
            self::BookingHold => 'Booking Hold',
            self::BookingHoldRelease => 'Booking Hold Release',
            self::Refund => 'Refund',
            self::ReferralCredit => 'Referral Credit',
            self::PromotionalCredit => 'Promotional Credit',
            self::AdminAdjustment => 'Admin Adjustment',
            self::Expiry => 'Expiry',
        };
    }
}
