<?php

declare(strict_types=1);

namespace App\Earnings\DTOs;

use Carbon\CarbonImmutable;

/**
 * A point-in-time available-withdrawal balance for one instructor in
 * one currency, derived exclusively from canonical records (releasable
 * unassigned earnings minus live reservations). Integer minor units
 * only. Display-only outside a transaction — request submission always
 * recalculates under row locks.
 */
final readonly class WithdrawalBalance
{
    public function __construct(
        public int $instructorId,
        public ?int $currencyId,
        public string $currencyCode,
        public int $grossEligibleMinor,
        public int $reservedMinor,
        public int $availableMinor,
        public int $minimumWithdrawalMinor,
        public ?int $maximumWithdrawalMinor,
        public bool $canWithdraw,
        public ?string $blockingReason,
        public CarbonImmutable $calculatedAt,
    ) {}
}
