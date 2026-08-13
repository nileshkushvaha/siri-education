<?php

declare(strict_types=1);

namespace App\Package\Enums;

/**
 * Personalized Instructor Package Proposal lifecycle. Draft/Submitted
 * are owned by the instructor-facing flow; Approved/Rejected are
 * owned exclusively by admin review; Accepted is owned exclusively by
 * the target student. No other caller may perform those transitions —
 * see InstructorPackageProposalService, the single writer.
 */
enum InstructorPackageProposalStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Accepted = 'accepted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
            self::Accepted => 'Accepted',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted => 'info',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Expired => 'gray',
            self::Accepted => 'success',
            self::Cancelled => 'gray',
        };
    }

    /** No further transition can ever occur from this state — Accepted is also immutable at the model level, see InstructorPackageProposal::booted(). */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Rejected, self::Expired, self::Accepted, self::Cancelled => true,
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
            self::Draft => [self::Submitted, self::Cancelled],
            self::Submitted => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved => [self::Accepted, self::Expired, self::Cancelled],
            self::Rejected, self::Expired, self::Accepted, self::Cancelled => [],
        };
    }
}
