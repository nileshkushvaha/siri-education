<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

/** Collection-side counterpart of App\Earnings\DTOs\PayoutProviderHealth — never the same class. */
final readonly class PaymentProviderHealth
{
    public function __construct(
        public bool $healthy,
        public ?string $safeMessage = null,
    ) {}
}
