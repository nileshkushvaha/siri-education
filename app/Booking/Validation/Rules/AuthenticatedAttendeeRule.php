<?php

declare(strict_types=1);

namespace App\Booking\Validation\Rules;

use App\Booking\Contracts\BookingRuleInterface;
use App\Booking\Contracts\BookingTypeInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Exceptions\BookingException;

/**
 * Phase 10.2C-Fix product decision: no guest booking. Every booking —
 * paid or free/demo — must be attributed to a real, authenticated
 * account. `CreateBookingData::attendeeId === null` was previously the
 * documented "this is a guest" signal (see CreateBookingData's own
 * docblock); it is now simply rejected outright, closing every
 * booking-creation entry point (the public wizard, the guest JSON API)
 * at the single chokepoint they all funnel through
 * (BookingService::request() → this pipeline).
 */
final class AuthenticatedAttendeeRule implements BookingRuleInterface
{
    public function check(CreateBookingData $data, BookingTypeInterface $type): void
    {
        if ($data->attendeeId === null) {
            throw new BookingException('Please log in or create an account to book a lesson.');
        }
    }
}
