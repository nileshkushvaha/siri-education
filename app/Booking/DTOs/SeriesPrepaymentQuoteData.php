<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

/**
 * What a student still owes across the classes of one repeating
 * schedule, and whether their wallet can cover it right now.
 *
 * Quotes only the classes that ACTUALLY EXIST as reservations. A
 * schedule may run far past the confirmation horizon, but money is
 * never collected for a class nobody is holding — so this is a bill for
 * what is reserved, never for the whole schedule.
 */
final readonly class SeriesPrepaymentQuoteData
{
    /**
     * @param  list<string>  $bookingIds  the payable classes, earliest first
     */
    public function __construct(
        public array $bookingIds,
        public int $totalMinor,
        public ?string $currencyCode,
        public int $walletBalanceMinor,
        /** Exactly what must be added to the wallet — never more. */
        public int $shortfallMinor,
        /** Classes of this schedule that are still only planned, so cannot be paid for yet. */
        public int $plannedCount = 0,
        /** Set when the classes cannot be paid for together at all. */
        public ?string $blockedReason = null,
    ) {}

    public function count(): int
    {
        return count($this->bookingIds);
    }

    public function isPayable(): bool
    {
        return $this->blockedReason === null && $this->bookingIds !== [] && $this->totalMinor > 0;
    }

    /** The wallet already covers the whole bill — no checkout is needed. */
    public function coveredByWallet(): bool
    {
        return $this->isPayable() && $this->shortfallMinor === 0;
    }

    /**
     * How much of the wallet balance this bill actually consumes — never
     * more than the bill itself. What a student is SHOWN as "paid from
     * balance" has to be this, not the raw balance: a 100 balance against
     * a 40 bill uses 40.
     */
    public function appliedBalanceMinor(): int
    {
        return max(0, min($this->walletBalanceMinor, $this->totalMinor));
    }
}
