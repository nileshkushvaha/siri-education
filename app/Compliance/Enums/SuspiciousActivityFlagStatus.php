<?php

declare(strict_types=1);

namespace App\Compliance\Enums;

/**
 * Lifecycle of one suspicious-activity flag. `canTransitionTo()` is
 * the single source of truth — TransitionSuspiciousActivityFlagStatusAction
 * guards every write through this, mirroring
 * InstructorQualityAlertStatus. A flag is evidence for human review,
 * never a sanction — no status here ever triggers suspension, payment
 * blocking, or any other automated consequence.
 */
enum SuspiciousActivityFlagStatus: string
{
    case Open = 'open';
    case InReview = 'in_review';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InReview => 'In Review',
            self::Resolved => 'Resolved',
            self::Dismissed => 'Dismissed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::InReview => 'info',
            self::Resolved => 'success',
            self::Dismissed => 'gray',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Open, self::InReview], strict: true);
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
            self::Open => [self::InReview, self::Resolved, self::Dismissed],
            self::InReview => [self::Resolved, self::Dismissed],
            self::Resolved, self::Dismissed => [],
        };
    }
}
