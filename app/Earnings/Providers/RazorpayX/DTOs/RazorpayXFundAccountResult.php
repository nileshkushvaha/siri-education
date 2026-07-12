<?php

declare(strict_types=1);

namespace App\Earnings\Providers\RazorpayX\DTOs;

final readonly class RazorpayXFundAccountResult
{
    public function __construct(
        public string $fundAccountId,
        public string $contactId,
        public string $status,
    ) {}
}
