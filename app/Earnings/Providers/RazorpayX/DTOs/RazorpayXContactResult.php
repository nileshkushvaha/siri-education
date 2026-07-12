<?php

declare(strict_types=1);

namespace App\Earnings\Providers\RazorpayX\DTOs;

final readonly class RazorpayXContactResult
{
    public function __construct(
        public string $contactId,
        public string $status,
        public ?string $referenceId,
    ) {}
}
