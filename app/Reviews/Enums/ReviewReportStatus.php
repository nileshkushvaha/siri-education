<?php

declare(strict_types=1);

namespace App\Reviews\Enums;

/**
 * Lifecycle of one review report. `canTransitionTo()` is the single
 * source of truth — TransitionReviewReportStatusAction guards every
 * write through this, mirroring StudentReviewStatus. `Withdrawn` is
 * reserved vocabulary — no submission/resolution path produces it yet.
 */
enum ReviewReportStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Upheld = 'upheld';
    case Dismissed = 'dismissed';
    case Duplicate = 'duplicate';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::UnderReview => 'Under Review',
            self::Upheld => 'Upheld',
            self::Dismissed => 'Dismissed',
            self::Duplicate => 'Duplicate',
            self::Withdrawn => 'Withdrawn',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::UnderReview => 'info',
            self::Upheld => 'danger',
            self::Dismissed => 'success',
            self::Duplicate, self::Withdrawn => 'gray',
        };
    }

    /** Not yet resolved — the only states a duplicate-report check considers "active". */
    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::UnderReview], strict: true);
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
            self::Pending => [self::UnderReview, self::Upheld, self::Dismissed, self::Duplicate, self::Withdrawn],
            self::UnderReview => [self::Upheld, self::Dismissed, self::Duplicate, self::Withdrawn],
            self::Upheld, self::Dismissed, self::Duplicate, self::Withdrawn => [],
        };
    }
}
