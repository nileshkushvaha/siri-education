<?php

declare(strict_types=1);

namespace App\Earnings\Exceptions;

use App\Earnings\Enums\InstructorWithdrawalStatus;

class InvalidWithdrawalTransitionException extends WithdrawalException
{
    public static function between(InstructorWithdrawalStatus $from, InstructorWithdrawalStatus $to): self
    {
        return new self(sprintf('A withdrawal request cannot move from %s to %s.', $from->value, $to->value));
    }
}
