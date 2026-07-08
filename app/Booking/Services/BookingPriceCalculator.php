<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\DTOs\BookingPriceData;
use App\Models\BookingType;
use App\Models\User;
use App\Settings\GeneralSettings;

/**
 * Single source of truth for what a booking costs, ahead of any
 * checkout/payment integration. Reuses existing pricing data only —
 * `booking_types.price`/`currency` remain the sole price source; no
 * discount or tax system exists yet, so those are always zero. A future
 * per-country/subject price matrix or discount/tax engine plugs in here
 * without changing any caller's contract (BookingPriceData already
 * carries the fields for both).
 */
final class BookingPriceCalculator
{
    public function __construct(
        private readonly GeneralSettings $settings,
    ) {}

    public function calculate(BookingType $type, ?User $student = null): BookingPriceData
    {
        $baseAmount = $type->is_paid ? (float) ($type->price ?? 0) : 0.0;
        $discountAmount = 0.0;
        $taxAmount = 0.0;
        $payableAmount = max(0.0, $baseAmount - $discountAmount + $taxAmount);

        return new BookingPriceData(
            baseAmount: $baseAmount,
            discountAmount: $discountAmount,
            taxAmount: $taxAmount,
            payableAmount: $payableAmount,
            currency: $type->currency ?? $this->studentCurrency($student) ?? $this->settings->default_currency,
            requiresPayment: $type->is_paid && $payableAmount > 0,
            isFreeBooking: ! $type->is_paid || $payableAmount <= 0,
        );
    }

    /** The student's country default currency — display fallback only, never a conversion. */
    private function studentCurrency(?User $student): ?string
    {
        return $student?->profile?->country?->defaultCurrency?->code;
    }
}
