<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class AccountWalletSummaryData
{
    public function __construct(
        public string $availableBalance,
        public string $currencyCode,
    ) {}
}
