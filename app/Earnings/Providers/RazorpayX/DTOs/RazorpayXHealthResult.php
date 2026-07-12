<?php

declare(strict_types=1);

namespace App\Earnings\Providers\RazorpayX\DTOs;

final readonly class RazorpayXHealthResult
{
    public function __construct(
        public bool $healthy,
        public ?string $safeMessage = null,
    ) {}
}
