<?php

declare(strict_types=1);

namespace App\Earnings\Enums;

/**
 * Reservation lifecycle: reserved while the withdrawal is live,
 * released when it is rejected/cancelled, consumed once a payout
 * attempt has actually paid the money out (Phase 16A), reversed if
 * that paid-out money is later returned by the provider. Only Reserved
 * and Consumed count as "unavailable" for balance purposes — see
 * InstructorWithdrawalBalanceService::calculate(). Reversed allocations
 * are never mutated back to Consumed: the history of what happened
 * stays visible, a new reservation is made instead.
 */
enum WithdrawalAllocationStatus: string
{
    case Reserved = 'reserved';
    case Released = 'released';
    case Consumed = 'consumed';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Reserved => 'Reserved',
            self::Released => 'Released',
            self::Consumed => 'Consumed',
            self::Reversed => 'Reversed',
        };
    }
}
