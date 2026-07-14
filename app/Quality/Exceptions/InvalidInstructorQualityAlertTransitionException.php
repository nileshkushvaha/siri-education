<?php

declare(strict_types=1);

namespace App\Quality\Exceptions;

use App\Quality\Enums\InstructorQualityAlertStatus;
use RuntimeException;

/** A resolution action attempted a status change the alert state machine forbids from its current status. */
final class InvalidInstructorQualityAlertTransitionException extends RuntimeException
{
    public static function between(InstructorQualityAlertStatus $from, InstructorQualityAlertStatus $to): self
    {
        return new self(sprintf(
            'An instructor quality alert cannot transition from "%s" to "%s".',
            $from->value,
            $to->value,
        ));
    }
}
