<?php

declare(strict_types=1);

namespace App\Earnings\Enums;

/**
 * Withdrawal request lifecycle. The review segment (submitted →
 * under_review → approved/rejected/cancelled) is owned by
 * InstructorWithdrawalService; the execution segment (approved →
 * processing → paid/failed/reversed) is owned exclusively by
 * InstructorPayoutExecutionService — no other caller may perform those
 * transitions. `failed → approved` exists only for an
 * explicit, authorized recovery of a pre-acceptance failure (the
 * provider never confirmed receipt), never an automatic retry.
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
    case Reversed = 'reversed';

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
            self::Reversed => 'Reversed',
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
            self::Reversed => 'danger',
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

    /** No further transition can ever occur from this state. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Rejected, self::Cancelled, self::Reversed => true,
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

    /** Paid requests can never be retried or re-queued; only reversed. */
    public function isExecutable(): bool
    {
        return $this === self::Approved;
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
            // Execution segment — InstructorPayoutExecutionService only.
            self::Approved => [self::Processing],
            // processing -> reversed is reconciliation-only: provider
            // evidence of a completed-then-reversed payout that was
            // never locally observed as paid.
            self::Processing => [self::Approved, self::Paid, self::Failed, self::Reversed],
            self::Paid => [self::Reversed],
            // Explicit authorized recovery only — never an automatic retry.
            self::Failed => [self::Approved],
            self::Rejected, self::Cancelled, self::Reversed => [],
        };
    }
}
