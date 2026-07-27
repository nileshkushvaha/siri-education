<?php

declare(strict_types=1);

namespace App\Exceptions\Instructor;

use App\Booking\DTOs\AvailabilityChangeImpact;
use RuntimeException;

/**
 * SRS §10.24: thrown by the availability/time-off
 * mutation services on the FIRST submission of a change that would
 * leave future confirmed bookings outside the instructor's effective
 * schedule. Nothing has been mutated when this is thrown — the caller
 * shows the carried impact (count + safe lesson times) and re-submits
 * with the impact fingerprint as the explicit acknowledgment.
 */
final class AvailabilityChangeRequiresConfirmationException extends RuntimeException
{
    public function __construct(public readonly AvailabilityChangeImpact $impact)
    {
        parent::__construct(sprintf(
            'This change affects %d confirmed upcoming lesson%s. Confirm to proceed — the lessons themselves remain scheduled and unchanged.',
            $impact->affectedCount,
            $impact->affectedCount === 1 ? '' : 's',
        ));
    }
}
