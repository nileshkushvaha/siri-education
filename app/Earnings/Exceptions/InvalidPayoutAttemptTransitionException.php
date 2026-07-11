<?php

declare(strict_types=1);

namespace App\Earnings\Exceptions;

use App\Earnings\Enums\InstructorPayoutAttemptStatus;

final class InvalidPayoutAttemptTransitionException extends EarningException
{
    public static function between(InstructorPayoutAttemptStatus $from, InstructorPayoutAttemptStatus $to): self
    {
        return new self(sprintf('Payout attempt cannot move from "%s" to "%s".', $from->value, $to->value));
    }
}
