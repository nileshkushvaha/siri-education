<?php

declare(strict_types=1);

namespace App\Quality\Enums;

/**
 * Lifecycle of one quality alert. `canTransitionTo()` is the single
 * source of truth — TransitionInstructorQualityAlertStatusAction
 * guards every write through this, mirroring StudentReviewStatus and
 * ReviewReportStatus. `Expired` is reserved vocabulary — no path in
 * this phase produces it.
 */
enum InstructorQualityAlertStatus: string
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
    case Duplicate = 'duplicate';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::UnderReview => 'Under Review',
            self::Resolved => 'Resolved',
            self::Dismissed => 'Dismissed',
            self::Duplicate => 'Duplicate',
            self::Expired => 'Expired',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::UnderReview => 'info',
            self::Resolved => 'success',
            self::Dismissed, self::Duplicate, self::Expired => 'gray',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Open, self::UnderReview], strict: true);
    }

    public function isTerminal(): bool
    {
        return ! $this->isActive();
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), strict: true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::UnderReview, self::Resolved, self::Dismissed, self::Duplicate, self::Expired],
            self::UnderReview => [self::Resolved, self::Dismissed, self::Duplicate, self::Expired],
            self::Resolved, self::Dismissed, self::Duplicate, self::Expired => [],
        };
    }
}
