<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

/**
 * Gateway-agnostic payment intent. The placeholder implementation
 * fills reference + amounts; a real gateway adds checkoutUrl.
 */
final readonly class PaymentIntentData
{
    public function __construct(
        public string $bookingId,
        public string $reference,
        public ?string $amount,
        public ?string $currency,
        public string $status,
        public ?string $checkoutUrl = null,
    ) {}
}
