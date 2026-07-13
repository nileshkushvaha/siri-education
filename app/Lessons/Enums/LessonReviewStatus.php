<?php

declare(strict_types=1);

namespace App\Lessons\Enums;

/**
 * Review lifecycle shared by attendance confirmations and
 * technical-issue reports (no pre-existing support-case module to
 * integrate with — the lesson Disputed status is an outcome state,
 * not a case lifecycle). Accepted/Rejected/Duplicate/Withdrawn/Expired
 * are final: changing them is a new decision, never an edit.
 */
enum LessonReviewStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Duplicate = 'duplicate';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::UnderReview => 'Under Review',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Duplicate => 'Duplicate',
            self::Withdrawn => 'Withdrawn',
            self::Expired => 'Expired',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::UnderReview => 'info',
            self::Accepted => 'success',
            self::Rejected => 'danger',
            self::Duplicate, self::Withdrawn, self::Expired => 'gray',
        };
    }

    /** Still awaiting an admin decision. */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Pending, self::UnderReview => true,
            default => false,
        };
    }
}
