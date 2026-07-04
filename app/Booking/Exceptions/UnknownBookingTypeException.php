<?php

declare(strict_types=1);

namespace App\Booking\Exceptions;

final class UnknownBookingTypeException extends BookingException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf('Booking type "%s" is not registered.', $key));
    }
}
