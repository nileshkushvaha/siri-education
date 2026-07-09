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
        /** Non-secret, provider-issued client key (e.g. Stripe's publishable key). */
        public ?string $publicKey = null,
        /** Single-use frontend confirmation token (Stripe only) — never persisted. */
        public ?string $clientSecret = null,
    ) {}
}
