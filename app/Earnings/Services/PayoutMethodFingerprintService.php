<?php

declare(strict_types=1);

namespace App\Earnings\Services;

use App\Earnings\Contracts\PayoutMethodFingerprintServiceInterface;
use App\Earnings\DTOs\PayoutMethodDetails;
use App\Earnings\Enums\PayoutMethodType;
use App\Earnings\Exceptions\PayoutMethodException;

/**
 * Keyed (HMAC-SHA256, app key) fingerprint of a payout destination's
 * normalized identifying fields. Deterministic for duplicate detection,
 * but — unlike a plain SHA hash — not brute-forceable from the short
 * account-number space without the key. The fingerprint is stored,
 * hidden from serialization, and never returned to any client.
 */
final class PayoutMethodFingerprintService implements PayoutMethodFingerprintServiceInterface
{
    public function generate(PayoutMethodType $type, ?int $countryId, string $currencyCode, PayoutMethodDetails $details): string
    {
        $identifier = $details->primaryIdentifier();

        if ($identifier === null) {
            throw new PayoutMethodException('A payout method needs an account number or IBAN.');
        }

        $material = implode('|', [
            $type->value,
            (string) ($countryId ?? ''),
            strtoupper($currencyCode),
            $identifier,
            PayoutMethodDetails::normalizeIdentifier($details->routingNumber) ?? '',
            PayoutMethodDetails::normalizeIdentifier($details->swiftBic) ?? '',
        ]);

        return hash_hmac('sha256', $material, $this->key());
    }

    private function key(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        if ($key === '') {
            throw new PayoutMethodException('Application encryption key is not configured.');
        }

        return $key;
    }
}
