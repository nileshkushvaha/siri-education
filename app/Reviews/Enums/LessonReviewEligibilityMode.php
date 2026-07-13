<?php

declare(strict_types=1);

namespace App\Reviews\Enums;

/**
 * What kind of review the eligibility window unlocks. `Disabled` is
 * never persisted by the opening action (a disabled policy simply
 * creates no record) — it exists so the enum can represent "no mode"
 * defensively wherever a mode is read without assuming a record exists.
 */
enum LessonReviewEligibilityMode: string
{
    case PublicReview = 'public_review';
    case PrivateFeedback = 'private_feedback';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::PublicReview => 'Public Review',
            self::PrivateFeedback => 'Private Feedback Only',
            self::Disabled => 'Disabled',
        };
    }
}
