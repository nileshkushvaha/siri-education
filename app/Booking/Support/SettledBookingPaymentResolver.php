<?php

declare(strict_types=1);

namespace App\Booking\Support;

use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Models\Booking;
use App\Models\BookingPayment;

/**
 * BookingPaymentSucceeded carries only the Booking, not the specific
 * BookingPayment row that settled it. Two listeners now need that row
 * — the invoice generator and the payment-success notifier — and they
 * must resolve the SAME attempt, or the receipt and the email would
 * describe different money.
 *
 * The convention ("latest captured payment for this booking") is the
 * one already established in BookingPaymentService::recordRefund()/
 * lockedUnresolvedCapturedPayment(); this class is that single
 * definition rather than a third and fourth copy of the query.
 */
final class SettledBookingPaymentResolver
{
    public function resolve(Booking $booking): ?BookingPayment
    {
        return BookingPayment::query()
            ->where('booking_id', $booking->id)
            ->where('status', BookingPaymentRecordStatus::Captured)
            ->latest('created_at')
            ->first();
    }
}
