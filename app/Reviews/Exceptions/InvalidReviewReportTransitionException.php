<?php

declare(strict_types=1);

namespace App\Reviews\Exceptions;

use App\Reviews\Enums\ReviewReportStatus;

/** A resolution action attempted a status change the report state machine forbids from its current status. */
final class InvalidReviewReportTransitionException extends ReviewEligibilityException
{
    public static function between(ReviewReportStatus $from, ReviewReportStatus $to): self
    {
        return new self(sprintf(
            'A review report cannot transition from "%s" to "%s".',
            $from->value,
            $to->value,
        ));
    }
}
