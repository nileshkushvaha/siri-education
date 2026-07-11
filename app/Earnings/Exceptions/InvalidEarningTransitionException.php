<?php

declare(strict_types=1);

namespace App\Earnings\Exceptions;

use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Enums\SettlementBatchStatus;

final class InvalidEarningTransitionException extends EarningException
{
    public static function between(InstructorEarningStatus $from, InstructorEarningStatus $to): self
    {
        return new self(sprintf('An earning cannot transition from "%s" to "%s".', $from->value, $to->value));
    }

    public static function betweenBatchStatuses(SettlementBatchStatus $from, SettlementBatchStatus $to): self
    {
        return new self(sprintf('A settlement batch cannot transition from "%s" to "%s".', $from->value, $to->value));
    }
}
