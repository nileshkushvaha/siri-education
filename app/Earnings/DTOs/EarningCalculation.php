<?php

declare(strict_types=1);

namespace App\Earnings\DTOs;

use App\Earnings\Enums\EarningCalculationType;

/** Immutable result of the earning calculation — integer minor units only. */
final readonly class EarningCalculation
{
    public function __construct(
        public EarningCalculationType $type,
        public string $currencyCode,
        public int $earningAmountMinor,
        public ?int $studentAmountMinor = null,
        public ?int $platformMarginMinor = null,
        public ?float $value = null,
    ) {}
}
