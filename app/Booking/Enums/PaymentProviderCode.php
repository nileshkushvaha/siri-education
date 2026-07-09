<?php

declare(strict_types=1);

namespace App\Booking\Enums;

/**
 * Typed catalogue of registered PaymentProviderInterface keys. The
 * source of truth for *which* provider is active is still
 * BookingSettings::payment_provider (a plain string, so settings
 * storage never depends on this enum) — this exists so code that
 * branches on provider identity does not compare against raw strings.
 */
enum PaymentProviderCode: string
{
    case Fake = 'fake';
    case Razorpay = 'razorpay';
    case Stripe = 'stripe';

    public function label(): string
    {
        return match ($this) {
            self::Fake => 'Fake (dev/test)',
            self::Razorpay => 'Razorpay',
            self::Stripe => 'Stripe',
        };
    }
}
