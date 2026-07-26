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

    /**
     * Option B: a gateway payment that settled successfully after its
     * booking had already gone terminal (cancelled/expired/
     * completed/no_show). The charge is real and captured at the
     * gateway, but the booking can no longer be confirmed for it, so
     * the amount is credited to the student's wallet instead of being
     * retained as booking revenue or refunded to the original payment
     * method. Distinct from Refund (an existing paid booking being
     * actively refunded) — this money was never "paid" from the
     * booking's own perspective in the first place.
     */
    case LatePaymentCredit = 'late_payment_credit';

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
            self::LatePaymentCredit => 'Late Payment Credit',
        };
    }
}
