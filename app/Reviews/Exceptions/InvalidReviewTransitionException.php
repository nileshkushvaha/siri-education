<?php

declare(strict_types=1);

namespace App\Reviews\Exceptions;

use App\Reviews\Enums\StudentReviewStatus;

/** A moderation action attempted a status change the state machine forbids from the review's current status. */
final class InvalidReviewTransitionException extends ReviewEligibilityException
{
    public static function between(StudentReviewStatus $from, StudentReviewStatus $to): self
    {
        return new self(sprintf(
            'A review cannot transition from "%s" to "%s".',
            $from->value,
            $to->value,
        ));
    }
}
