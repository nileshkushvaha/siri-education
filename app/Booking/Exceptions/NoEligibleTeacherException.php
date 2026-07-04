<?php

declare(strict_types=1);

namespace App\Booking\Exceptions;

use App\Booking\DTOs\AssignmentCriteriaData;

final class NoEligibleTeacherException extends BookingException
{
    public static function for(AssignmentCriteriaData $criteria): self
    {
        return new self(sprintf(
            'No teacher is available for %s (grade %d) at %s.',
            $criteria->subject,
            $criteria->grade,
            $criteria->startsAt->toIso8601String(),
        ));
    }
}
