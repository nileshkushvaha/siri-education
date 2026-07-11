<?php

declare(strict_types=1);

namespace App\Earnings\DTOs;

final readonly class PayoutProviderHealth
{
    public function __construct(
        public bool $healthy,
        public ?string $safeMessage = null,
    ) {}
}
