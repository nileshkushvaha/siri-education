<?php

declare(strict_types=1);

namespace App\Booking\Exceptions;

/**
 * SRS §11.26 — the configured reschedule_limit has already been
 * reached for this booking. No setting name/value is carried on this
 * exception (mirrors FreeDemoAlreadyUsedException) because callers
 * (Livewire, Filament) render getMessage() verbatim to the user.
 */
final class RescheduleLimitReachedException extends BookingException
{
    public static function make(): self
    {
        return new self('You have reached the reschedule limit for this lesson.');
    }
}
