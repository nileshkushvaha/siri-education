<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\AvailabilityServiceInterface;
use App\Booking\Contracts\BookingTypeRepositoryInterface;
use App\Booking\Contracts\TeacherAssignmentServiceInterface;
use App\Booking\Contracts\TeacherCandidateRepositoryInterface;
use App\Booking\DTOs\AssignmentCriteriaData;
use App\Booking\Exceptions\NoEligibleTeacherException;
use App\Booking\Exceptions\SlotUnavailableException;
use App\Booking\Registry\AssignmentStrategyRegistry;
use App\Models\User;
use App\Settings\BookingSettings;

/**
 * Guests never pick a teacher. This service turns criteria into the
 * best teacher in three phases:
 *   1. hard match — subject/grade + approved instructor (repository)
 *   2. hard filter — bookable slot (availability, buffer, daily cap)
 *   3. ranking — the configured AssignmentStrategyInterface
 */
final class TeacherAssignmentService implements TeacherAssignmentServiceInterface
{
    public function __construct(
        private readonly TeacherCandidateRepositoryInterface $candidates,
        private readonly AvailabilityServiceInterface $availability,
        private readonly BookingTypeRepositoryInterface $types,
        private readonly AssignmentStrategyRegistry $strategies,
        private readonly BookingSettings $settings,
    ) {}

    public function assign(AssignmentCriteriaData $criteria): User
    {
        $type = $this->types->requireActiveByKey($criteria->typeKey);

        $bookable = $this->candidates
            ->eligible($criteria)
            ->filter(function (User $teacher) use ($criteria, $type): bool {
                try {
                    $this->availability->ensureAvailable(
                        $teacher->id,
                        $criteria->startsAt,
                        $criteria->endsAt(),
                        bufferMinutes: $type->buffer_minutes,
                    );

                    return true;
                } catch (SlotUnavailableException) {
                    return false;
                }
            })
            ->values();

        $strategy = $this->strategies->get($this->settings->assignment_strategy);

        return $strategy->assign($criteria, $bookable)
            ?? throw NoEligibleTeacherException::for($criteria);
    }
}
