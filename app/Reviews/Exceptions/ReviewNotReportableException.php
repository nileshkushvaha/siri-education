<?php

declare(strict_types=1);

namespace App\Reviews\Exceptions;

use App\Models\LessonReview;

/** The review is not currently eligible to be reported — wrong status/mode, or reporting is disabled. */
final class ReviewNotReportableException extends ReviewEligibilityException
{
    public static function forReview(LessonReview $review): self
    {
        return new self(sprintf(
            'Review %s cannot be reported — status "%s" / mode "%s" is not reportable.',
            $review->id,
            $review->status->value,
            $review->review_mode->value,
        ));
    }

    public static function reportingDisabled(): self
    {
        return new self('Review reporting is currently disabled.');
    }
}
