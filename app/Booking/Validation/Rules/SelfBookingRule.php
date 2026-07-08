<?php

declare(strict_types=1);

namespace App\Booking\Validation\Rules;

use App\Booking\Contracts\BookingRuleInterface;
use App\Booking\Contracts\BookingTypeInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Exceptions\BookingException;

/**
 * A dual-role user (student who is also an instructor) cannot book a
 * session with themselves. Guests always have a null attendeeId, so
 * this never affects the guest flow.
 */
final class SelfBookingRule implements BookingRuleInterface
{
    public function check(CreateBookingData $data, BookingTypeInterface $type): void
    {
        if ($data->attendeeId !== null && $data->attendeeId === $data->hostId) {
            throw new BookingException('You cannot book a session with yourself.');
        }
    }
}
