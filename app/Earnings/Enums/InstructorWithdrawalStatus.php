<?php

declare(strict_types=1);

namespace App\Earnings\Enums;

/**
 * Withdrawal request lifecycle. Phase 15 operates only the review
 * segment (submitted → under_review → approved/rejected/cancelled);
 * processing/paid/failed exist in the matrix for the future payout-
 * execution phase and are unreachable from any Phase 15 UI or service
 * method. InstructorWithdrawalService guards every write.
 */
enum InstructorWithdrawalStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under Review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
            self::Processing => 'Processing',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Submitted => 'info',
            self::UnderReview => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Cancelled => 'gray',
            self::Processing => 'warning',
            self::Paid => 'success',
            self::Failed => 'danger',
        };
    }

    /** Requests still holding (or about to hold) reserved earnings. */
    public function isActive(): bool
    {
        return match ($this) {
            self::Submitted, self::UnderReview, self::Approved, self::Processing => true,
            default => false,
        };
    }

    /** Terminal in Phase 15 — no further transition is possible. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Rejected, self::Cancelled, self::Paid => true,
            default => false,
        };
    }

    /** Statuses whose entry releases the reserved earnings. */
    public function releasesReservation(): bool
    {
        return match ($this) {
            self::Rejected, self::Cancelled => true,
            default => false,
        };
    }

    /** The instructor may still cancel from these states. */
    public function isInstructorCancellable(): bool
    {
        return match ($this) {
            self::Submitted, self::UnderReview => true,
            default => false,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), strict: true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Submitted => [self::UnderReview, self::Approved, self::Rejected, self::Cancelled],
            self::UnderReview => [self::Approved, self::Rejected, self::Cancelled],
            // Reserved for the payout-execution phase — no Phase 15 code path
            // performs these transitions.
            self::Approved => [self::Processing],
            self::Processing => [self::Paid, self::Failed],
            self::Failed => [self::Processing],
            self::Rejected, self::Cancelled, self::Paid => [],
        };
    }
}
