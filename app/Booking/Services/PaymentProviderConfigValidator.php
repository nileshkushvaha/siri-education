<?php

declare(strict_types=1);

namespace App\Booking\Services;

/**
 * Format-only credential validation — never calls the gateway. A
 * random string ("asdf1234") must not be treated as a valid key just
 * because the field is non-blank; each gateway's key formats are
 * stable and documented, so a regex check catches obviously-invalid
 * values before a real API call would (in production) or before a
 * test would (never — tests must stub the HTTP client, not rely on
 * this validator to detect fakes for them).
 */
final class PaymentProviderConfigValidator
{
    public function isValidRazorpayKeyId(?string $value): bool
    {
        return is_string($value) && preg_match('/^rzp_(test|live)_[A-Za-z0-9_]+$/', $value) === 1;
    }

    public function isValidStripeSecretKey(?string $value): bool
    {
        return is_string($value) && preg_match('/^sk_(test|live)_[A-Za-z0-9_]+$/', $value) === 1;
    }

    public function isValidStripePublishableKey(?string $value): bool
    {
        return is_string($value) && preg_match('/^pk_(test|live)_[A-Za-z0-9_]+$/', $value) === 1;
    }

    public function isValidStripeWebhookSecret(?string $value): bool
    {
        return is_string($value) && preg_match('/^whsec_[A-Za-z0-9_]+$/', $value) === 1;
    }
}
