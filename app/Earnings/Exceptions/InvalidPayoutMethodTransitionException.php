<?php

declare(strict_types=1);

namespace App\Earnings\Exceptions;

use App\Earnings\Enums\PayoutMethodStatus;

class InvalidPayoutMethodTransitionException extends PayoutMethodException
{
    public static function between(PayoutMethodStatus $from, PayoutMethodStatus $to): self
    {
        return new self(sprintf('A payout method cannot move from %s to %s.', $from->value, $to->value));
    }
}
