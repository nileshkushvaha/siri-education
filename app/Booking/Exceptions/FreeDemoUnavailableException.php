<?php

declare(strict_types=1);

namespace App\Booking\Exceptions;

/**
 * SRS-20-5 — the platform-wide FeatureSettings::demo_lessons_enabled
 * toggle is off. No setting name or internal state is carried on this
 * exception, since BookingWizard renders getMessage() verbatim to the
 * student as a banner.
 */
final class FreeDemoUnavailableException extends BookingException
{
    public static function make(): self
    {
        return new self('Free demo lessons are currently unavailable. You can still book a paid lesson.');
    }
}
