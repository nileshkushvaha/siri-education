<?php

declare(strict_types=1);

namespace App\Booking\Exceptions;

/**
 * SRS 11.13/11.39 — one free demo per student-instructor pair. No
 * identifiers are carried on this exception (unlike
 * DuplicateBookingException) because BookingWizard renders
 * getMessage() verbatim to the student as a banner.
 */
final class FreeDemoAlreadyUsedException extends BookingException
{
    public static function make(): self
    {
        return new self(
            "You've already used your free demo lesson with this instructor. ".
            'You can book a paid lesson with them, or try a free demo with a different instructor.',
        );
    }
}
