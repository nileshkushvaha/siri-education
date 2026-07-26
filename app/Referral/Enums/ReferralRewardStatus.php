<?php

declare(strict_types=1);

namespace App\Referral\Enums;

/**
 * The reward lifecycle (SRS 16.15). A row is only created once
 * eligibility is decided, so there is no separate "pending" state:
 *
 *   Eligible      awaiting credit_ready_at, then credited by the one
 *                 creditReward() path
 *   Held          fraud review (campaign flag) or currency mismatch —
 *                 never auto-credited; an admin decision resolves
 *   Credited      wallet ledger entry posted (CHECK-enforced linkage)
 *   CreditFailed  wallet unusable at credit time; retryable
 *   Rejected      terminal, never creditable (ineligible before credit,
 *                 or a zero-minor-unit calculation)
 *   Reversed      credited then invalidated; separate reversal ledger
 *                 entry (CHECK-enforced linkage)
 */
enum ReferralRewardStatus: string
{
    case Eligible = 'eligible';
    case Held = 'held';
    case Credited = 'credited';
    case CreditFailed = 'credit_failed';
    case Rejected = 'rejected';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Eligible => 'Eligible',
            self::Held => 'Held for review',
            self::Credited => 'Credited',
            self::CreditFailed => 'Credit failed',
            self::Rejected => 'Not eligible',
            self::Reversed => 'Reversed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Eligible => 'info',
            self::Held => 'warning',
            self::Credited => 'success',
            self::CreditFailed => 'danger',
            self::Rejected => 'gray',
            self::Reversed => 'danger',
        };
    }

    /** Statuses that consume a slot of the campaign's class cap. Rejected never does; Reversed deliberately still does (no refund-cycling to farm slots). */
    public function countsTowardClassCap(): bool
    {
        return $this !== self::Rejected;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Reversed], true);
    }
}
