<?php

declare(strict_types=1);

namespace App\Booking\Exceptions;

use App\Booking\Enums\BookingStatus;

final class InvalidStatusTransitionException extends BookingException
{
    public static function between(BookingStatus $from, BookingStatus $to): self
    {
        return new self(sprintf(
            'A booking cannot move from "%s" to "%s".',
            $from->value,
            $to->value,
        ));
    }
}
