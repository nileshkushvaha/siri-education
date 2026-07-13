<?php

declare(strict_types=1);

namespace App\Reviews\Enums;

/**
 * Lifecycle of one submitted student review. Phase 17I only ever
 * produces Submitted, Private, or Flagged — Hidden/Rejected/Archived
 * are reserved vocabulary for the future moderation phase (no code
 * path writes them yet), so that phase needs no migration to add them.
 */
enum StudentReviewStatus: string
{
    /** Public-review candidate, submitted and awaiting moderation/publication. */
    case Submitted = 'submitted';

    /** Private feedback — never enters public moderation/publication. */
    case Private = 'private';

    /** Basic safety detection tripped — held for moderation, never auto-published. */
    case Flagged = 'flagged';

    case Hidden = 'hidden';
    case Rejected = 'rejected';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::Private => 'Private',
            self::Flagged => 'Flagged',
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
            self::Hidden => 'gray',
            self::Rejected => 'danger',
            self::Archived => 'gray',
        };
    }

    /** Never publicly visible or aggregated — always true in Phase 17I (nothing publishes yet). */
    public function isPubliclyVisible(): bool
    {
        return false;
    }
}
