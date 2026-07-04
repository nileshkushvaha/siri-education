<?php

declare(strict_types=1);

namespace App\Booking\Exceptions;

use App\Booking\DTOs\CreateBookingData;

final class DuplicateBookingException extends BookingException
{
    public static function for(CreateBookingData $data): self
    {
        return new self(sprintf(
            'An active "%s" booking already exists for this attendee with host #%d at %s.',
            $data->typeKey,
            $data->hostId,
            $data->startsAt->toIso8601String(),
        ));
    }
}
