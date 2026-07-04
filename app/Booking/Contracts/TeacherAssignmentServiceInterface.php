<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\AssignmentCriteriaData;
use App\Booking\Exceptions\NoEligibleTeacherException;
use App\Models\User;

/**
 * Auto-assigns the best teacher for a request. Guests and students
 * never select teachers — callers pass criteria and receive the
 * assigned teacher, then feed their id into CreateBookingData.
 */
interface TeacherAssignmentServiceInterface
{
    /** @throws NoEligibleTeacherException when nobody matches */
    public function assign(AssignmentCriteriaData $criteria): User;
}
