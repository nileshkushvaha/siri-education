<?php

declare(strict_types=1);

namespace App\Reviews\Enums;

/**
 * Lifecycle of one review-eligibility record. There is exactly one
 * record per (lesson, student) — states transition on the same row;
 * nothing here is ever deleted or duplicated.
 */
enum LessonReviewEligibilityStatus: string
{
    case Open = 'open';
    case Used = 'used';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case ManualReview = 'manual_review';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Used => 'Used',
            self::Expired => 'Expired',
            self::Revoked => 'Revoked',
            self::ManualReview => 'Manual Review',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'success',
            self::Used => 'info',
            self::Expired => 'gray',
            self::Revoked => 'danger',
            self::ManualReview => 'warning',
        };
    }

    /** Still awaiting review submission — the only state the expiration sweep touches. */
    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
