<?php

declare(strict_types=1);

namespace App\Alerts\Exceptions;

use App\Alerts\Enums\OperationalAlertStatus;
use RuntimeException;

final class InvalidOperationalAlertTransitionException extends RuntimeException
{
    public static function between(OperationalAlertStatus $from, OperationalAlertStatus $to): self
    {
        return new self(sprintf('Cannot move an operational alert from "%s" to "%s".', $from->label(), $to->label()));
    }
}
