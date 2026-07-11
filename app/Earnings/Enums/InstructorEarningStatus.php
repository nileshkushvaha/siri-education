<?php

declare(strict_types=1);

namespace App\Earnings\Enums;

enum InstructorEarningStatus: string
{
    case PendingHold = 'pending_hold';
    case Releasable = 'releasable';
    case Settled = 'settled';
    case Reversed = 'reversed';
    case DisputedHold = 'disputed_hold';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PendingHold => 'Pending Hold',
            self::Releasable => 'Releasable',
            self::Settled => 'Settled',
            self::Reversed => 'Reversed',
            self::DisputedHold => 'Disputed Hold',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PendingHold => 'warning',
            self::Releasable => 'info',
            self::Settled => 'success',
            self::Reversed => 'danger',
            self::DisputedHold => 'danger',
            self::Cancelled => 'gray',
        };
    }

    /** Money already paid out (or written off) — never re-enters any flow. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Settled, self::Reversed, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * Single source of truth for the earning state machine.
     * TransitionInstructorEarningAction guards every write.
     */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), strict: true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingHold => [self::Releasable, self::DisputedHold, self::Reversed, self::Cancelled],
            self::Releasable => [self::Settled, self::DisputedHold, self::Reversed, self::Cancelled],
            // Dispute resolved → back on hold (the release sweep re-promotes).
            self::DisputedHold => [self::PendingHold, self::Reversed, self::Cancelled],
            // Clawback of settled (already paid) money is a future, manual concern.
            self::Settled, self::Reversed, self::Cancelled => [],
        };
    }
}
