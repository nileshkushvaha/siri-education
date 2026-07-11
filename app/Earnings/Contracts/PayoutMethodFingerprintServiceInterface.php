<?php

declare(strict_types=1);

namespace App\Earnings\Contracts;

use App\Earnings\DTOs\PayoutMethodDetails;
use App\Earnings\Enums\PayoutMethodType;

interface PayoutMethodFingerprintServiceInterface
{
    /**
     * Deterministic keyed (HMAC) fingerprint of the normalized
     * identifying fields — duplicate detection that never reveals
     * account data and is never returned to clients.
     */
    public function generate(PayoutMethodType $type, ?int $countryId, string $currencyCode, PayoutMethodDetails $details): string;
}
