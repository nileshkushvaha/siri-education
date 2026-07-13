<?php

declare(strict_types=1);

namespace App\Reviews\Enums;

/**
 * Lifecycle of one submitted student review. Phase 17I produces
 * Submitted, Private, or Flagged; Phase 17J adds Published plus the
 * moderation transitions (Hidden/Rejected/Archived were reserved
 * vocabulary in 17I, unused until now). `canTransitionTo()` is the
 * single source of truth — TransitionReviewStatusAction guards every
 * write, mirroring LessonStatus/InstructorEarningStatus.
 */
enum StudentReviewStatus: string
{
    /** Public-review candidate, submitted and awaiting moderation/publication. */
    case Submitted = 'submitted';

    /** Private feedback — never enters public moderation/publication. */
    case Private = 'private';

    /** Basic safety detection tripped (or an admin flagged it) — held for moderation. */
    case Flagged = 'flagged';

    /** Live as a public review. */
    case Published = 'published';

    /** Was public, pulled from view by an admin — never deleted. */
    case Hidden = 'hidden';

    case Rejected = 'rejected';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::Private => 'Private',
            self::Flagged => 'Flagged',
            self::Published => 'Published',
            self::Hidden => 'Hidden',
            self::Rejected => 'Rejected',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Submitted => 'info',
            self::Private => 'gray',
            self::Flagged => 'warning',
            self::Published => 'success',
            self::Hidden => 'gray',
            self::Rejected => 'danger',
            self::Archived => 'gray',
        };
    }

    /** The only status a public profile/aggregate (a future phase) may ever surface. */
    public function isPubliclyVisible(): bool
    {
        return $this === self::Published;
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Rejected, self::Archived => true,
            default => false,
        };
    }

    /**
     * Single source of truth for the moderation state machine.
     * TransitionReviewStatusAction guards every write through this.
     */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), strict: true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Submitted => [self::Published, self::Flagged, self::Rejected],
            self::Flagged => [self::Published, self::Private, self::Rejected],
            self::Published => [self::Hidden],
            self::Hidden => [self::Published, self::Archived],
            self::Private => [self::Rejected, self::Archived],
            self::Rejected, self::Archived => [],
        };
    }
}
