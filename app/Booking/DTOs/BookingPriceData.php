<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

/**
 * The result of BookingPriceCalculator::calculate() — the single source
 * of truth for what a booking type costs before checkout exists.
 * discountAmount/taxAmount are always zero today (no promo or tax
 * system exists yet); they are modeled now so a future discount/tax
 * engine is additive, not a breaking change to every caller.
 */
final readonly class BookingPriceData
{
    public function __construct(
        public float $baseAmount,
        public float $discountAmount,
        public float $taxAmount,
        public float $payableAmount,
        public string $currency,
        public bool $requiresPayment,
        public bool $isFreeBooking,
    ) {}
}
