<?php

declare(strict_types=1);

namespace App\Booking\Exceptions;

use Carbon\CarbonImmutable;

final class SlotUnavailableException extends BookingException
{
    public static function for(int $hostId, CarbonImmutable $startsAt): self
    {
        return new self(sprintf(
            'Host #%d is not available at %s.',
            $hostId,
            $startsAt->toIso8601String(),
        ));
    }
}
