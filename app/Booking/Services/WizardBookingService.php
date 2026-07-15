<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\AvailabilityServiceInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\BookingTypeRepositoryInterface;
use App\Booking\Contracts\TeacherAssignmentServiceInterface;
use App\Booking\Contracts\TeacherCandidateRepositoryInterface;
use App\Booking\Contracts\WizardBookingServiceInterface;
use App\Booking\DTOs\AssignmentCriteriaData;
use App\Booking\DTOs\AvailabilityQueryData;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\DTOs\TimeSlotData;
use App\Booking\DTOs\WizardBookingData;
use App\Booking\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Authenticated-student wizard booking flow (Phase 17U.3 — renamed
 * from GuestBookingService; every caller is logged in). The auto-
 * assignment capability (pick any eligible teacher, or lock a specific
 * one) is what distinguishes this from StudentBookingServiceInterface,
 * which always requires an explicit teacher choice.
 */
final class WizardBookingService implements WizardBookingServiceInterface
{
    public function __construct(
        private readonly BookingServiceInterface $bookings,
        private readonly BookingTypeRepositoryInterface $types,
        private readonly TeacherCandidateRepositoryInterface $candidates,
        private readonly TeacherAssignmentServiceInterface $assigner,
        private readonly AvailabilityServiceInterface $availability,
    ) {}

    public function availableDates(
        string $typeKey,
        string $subject,
        int $grade,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $timezone = 'UTC',
        ?int $teacherId = null,
    ): Collection {
        $type = $this->types->requireActiveByKey($typeKey);
        $totalDays = (int) $from->startOfDay()->diffInDays($to->endOfDay()) + 1;

        // Stream per teacher and keep only the date strings — never the
        // slot objects — and stop as soon as every day is covered.
        $found = [];

        foreach ($this->eligibleTeachers($typeKey, $subject, $grade, $from, $type->duration_minutes, $teacherId) as $teacher) {
            $slots = $this->availability->slots(
                new AvailabilityQueryData($teacher->id, $typeKey, $from, $to, $timezone),
            );

            foreach ($slots as $slot) {
                $found[$slot->startsAt->toDateString()] = true;
            }

            if (count($found) >= $totalDays) {
                break;
            }
        }

        return collect(array_keys($found))->sort()->values();
    }

    public function availableSlots(
        string $typeKey,
        string $subject,
        int $grade,
        CarbonImmutable $date,
        string $timezone = 'UTC',
        ?int $teacherId = null,
    ): Collection {
        $from = $date->setTimezone($timezone)->startOfDay();

        return $this
            ->slotsAcrossTeachers($typeKey, $subject, $grade, $from, $from->addDay(), $timezone, $teacherId)
            ->unique(fn (TimeSlotData $slot): int => $slot->startsAt->getTimestamp())
            ->sortBy(fn (TimeSlotData $slot): int => $slot->startsAt->getTimestamp())
            ->values();
    }

    public function book(WizardBookingData $data): Booking
    {
        // Defense-in-depth: the route itself already requires 'auth', but
        // this service is the single chokepoint every wizard submission
        // funnels through — it must refuse gracefully even if some future
        // caller reaches it without going through that middleware, rather
        // than crash on the non-nullable CreateBookingData::$studentId.
        if (! auth()->check()) {
            throw new BookingException('Please log in or create an account to book a lesson.');
        }

        $type = $this->types->requireActiveByKey($data->typeKey);

        $criteria = new AssignmentCriteriaData(
            typeKey: $data->typeKey,
            subject: $data->subject,
            grade: $data->grade,
            startsAt: $data->startsAt,
            durationMinutes: $type->duration_minutes,
            timezone: $data->timezone,
        );

        if ($data->teacherId !== null) {
            if (! $this->candidates->isEligible($data->teacherId, $criteria)) {
                throw new BookingException('This instructor is not available for the selected subject and grade.');
            }

            $teacherId = $data->teacherId;
        } else {
            $teacherId = $this->assigner->assign($criteria)->id;
        }

        return $this->bookings->request(new CreateBookingData(
            typeKey: $data->typeKey,
            studentId: auth()->id(),
            instructorId: $teacherId,
            startsAt: $data->startsAt,
            durationMinutes: $type->duration_minutes,
            timezone: $data->timezone,
            notes: $data->notes,
            meta: ['subject' => $data->subject, 'grade' => $data->grade],
        ));
    }

    /** @return Collection<int, TimeSlotData> */
    private function slotsAcrossTeachers(
        string $typeKey,
        string $subject,
        int $grade,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $timezone,
        ?int $teacherId = null,
    ): Collection {
        $type = $this->types->requireActiveByKey($typeKey);

        return $this
            ->eligibleTeachers($typeKey, $subject, $grade, $from, $type->duration_minutes, $teacherId)
            ->flatMap(fn (User $teacher): Collection => $this->availability->slots(
                new AvailabilityQueryData($teacher->id, $typeKey, $from, $to, $timezone),
            ));
    }

    /** @return Collection<int, User> */
    private function eligibleTeachers(string $typeKey, string $subject, int $grade, CarbonImmutable $startsAt, int $duration, ?int $teacherId = null): Collection
    {
        $criteria = new AssignmentCriteriaData($typeKey, $subject, $grade, $startsAt, $duration);

        if ($teacherId === null) {
            return $this->candidates->eligible($criteria);
        }

        if (! $this->candidates->isEligible($teacherId, $criteria)) {
            return new Collection;
        }

        return User::query()
            ->whereKey($teacherId)
            ->with('profile')
            ->get();
    }
}
