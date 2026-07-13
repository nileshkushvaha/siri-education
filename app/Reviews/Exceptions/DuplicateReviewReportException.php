<?php

declare(strict_types=1);

namespace App\Reviews\Exceptions;

use App\Models\LessonReview;
use App\Reviews\Enums\ReviewReportReason;

/** This reporter already has an active (Pending/UnderReview) report against this review for the same reason. */
final class DuplicateReviewReportException extends ReviewEligibilityException
{
    public static function forReview(LessonReview $review, ReviewReportReason $reason): self
    {
        return new self(sprintf(
            'You have already reported review %s for "%s".',
            $review->id,
            $reason->label(),
        ));
    }
}
