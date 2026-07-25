<?php

declare(strict_types=1);

namespace App\Compliance\Exceptions;

use App\Compliance\Enums\SuspiciousActivityFlagStatus;
use RuntimeException;

/** A review action attempted a status change the flag state machine forbids from its current status. */
final class InvalidSuspiciousActivityFlagTransitionException extends RuntimeException
{
    public static function between(SuspiciousActivityFlagStatus $from, SuspiciousActivityFlagStatus $to): self
    {
        return new self(sprintf(
            'A suspicious activity flag cannot transition from "%s" to "%s".',
            $from->value,
            $to->value,
        ));
    }
}
