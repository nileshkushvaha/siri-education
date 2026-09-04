<?php

declare(strict_types=1);

namespace App\Booking\Exceptions;

/**
 * A student or instructor tried to cancel or reschedule a lesson whose
 * start time has passed. Admins are not subject to this — a delivered or
 * missed lesson is settled through the lesson outcome flow, and an admin
 * cancellation remains a deliberate operational override.
 */
final class LessonAlreadyStartedException extends BookingException
{
    public static function forCancellation(): self
    {
        return new self('This lesson has already started, so it can no longer be cancelled. It will be marked complete automatically.');
    }

    public static function forReschedule(): self
    {
        return new self('This lesson has already started, so it can no longer be rescheduled.');
    }
}
