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
use App\Booking\DTOs\RecurrenceData;
use App\Booking\DTOs\RecurringBookingResult;
use App\Booking\DTOs\TimeSlotData;
use App\Booking\DTOs\WizardBookingData;
use App\Booking\Enums\RecurrenceFrequency;
use App\Booking\Exceptions\BookingException;
use App\Booking\Types\FreeDemoType;
use App\Contracts\StudentFinancialVerificationGate;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Authenticated-student wizard booking flow — every caller is logged
 * in. The auto-assignment capability (pick any eligible teacher, or
 * lock a specific one) is what distinguishes this from
 * StudentBookingServiceInterface, which always requires an explicit
 * teacher choice.
 */
final class WizardBookingService implements WizardBookingServiceInterface
{
    public function __construct(
        private readonly BookingServiceInterface $bookings,
        private readonly BookingTypeRepositoryInterface $types,
        private readonly TeacherCandidateRepositoryInterface $candidates,
        private readonly TeacherAssignmentServiceInterface $assigner,
        private readonly AvailabilityServiceInterface $availability,
        private readonly StudentFinancialVerificationGate $financialVerification,
        private readonly DemoAvailabilityResolver $demoAvailability,
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
        $this->assertAuthenticated();
        $type = $this->types->requireActiveByKey($data->typeKey);
        $this->financialVerification->assertEligible(auth()->user(), $type);
        $teacherId = $this->resolveTeacher($data, $type);

        return $this->bookings->request($this->occurrenceData($data, $type, $data->startsAt, $teacherId));
    }

    /**
     * Free Demo never accepts recurrence (BookingException). The teacher
     * for the first occurrence (locked, or auto-assigned) is reused for
     * every later occurrence — a recurring series is with one instructor.
     */
    public function bookRecurring(WizardBookingData $data, RecurrenceData $recurrence): RecurringBookingResult
    {
        $this->assertAuthenticated();
        $type = $this->types->requireActiveByKey($data->typeKey);

        $this->financialVerification->assertEligible(auth()->user(), $type);

        if (! $type->is_paid) {
            throw new BookingException('Recurring sessions are only available for paid booking types.');
        }

        $teacherId = $this->resolveTeacher($data, $type);
        $occurrences = max(2, min($recurrence->occurrences, RecurrenceData::MAX_OCCURRENCES));
        $groupId = (string) Str::uuid();

        $booked = new Collection;
        $failures = [];

        for ($i = 0; $i < $occurrences; $i++) {
            $startsAt = $recurrence->nextStartsAt($data->startsAt, $i);

            try {
                $booked->push($this->bookings->request($this->occurrenceData($data, $type, $startsAt, $teacherId, ['recurring_group' => $groupId], $recurrence->frequency)));
            } catch (BookingException $e) {
                $failures[$startsAt->toIso8601String()] = $e->getMessage();
            }
        }

        if ($booked->isEmpty()) {
            throw new BookingException('None of the requested sessions could be booked: '.implode(' ', $failures));
        }

        return new RecurringBookingResult($groupId, $booked, $failures);
    }

    private function assertAuthenticated(): void
    {
        // Defense-in-depth: the route itself already requires 'auth', but
        // this service is the single chokepoint every wizard submission
        // funnels through — it must refuse gracefully even if some future
        // caller reaches it without going through that middleware, rather
        // than crash on the non-nullable CreateBookingData::$studentId.
        if (! auth()->check()) {
            throw new BookingException('Please log in or create an account to book a lesson.');
        }
    }

    private function resolveTeacher(WizardBookingData $data, BookingType $type): int
    {
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

            return $data->teacherId;
        }

        return $this->assigner->assign($criteria)->id;
    }

    /** @param array<string, mixed> $extraMeta */
    private function occurrenceData(WizardBookingData $data, BookingType $type, CarbonImmutable $startsAt, int $teacherId, array $extraMeta = [], ?RecurrenceFrequency $recurrenceFrequency = null): CreateBookingData
    {
        return new CreateBookingData(
            typeKey: $data->typeKey,
            studentId: auth()->id(),
            instructorId: $teacherId,
            startsAt: $startsAt,
            durationMinutes: $type->duration_minutes,
            timezone: $data->timezone,
            notes: $data->notes,
            meta: ['subject' => $data->subject, 'grade' => $data->grade, ...$extraMeta],
            recurrenceFrequency: $recurrenceFrequency,
        );
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

    /**
     * The one place both availableDates() and availableSlots() (via
     * slotsAcrossTeachers()) funnel through
     * before any teacher-eligibility query or availability expansion.
     * An explicit free-demo scheduling request must never return
     * teachers/dates/slots implying a new demo can be created while the
     * platform-wide feature is unavailable — and returning empty here,
     * before eligibility/availability queries run, is also what keeps
     * this from doing unnecessary work once demos are disabled.
     *
     * @return Collection<int, User>
     */
    private function eligibleTeachers(string $typeKey, string $subject, int $grade, CarbonImmutable $startsAt, int $duration, ?int $teacherId = null): Collection
    {
        if ($typeKey === FreeDemoType::KEY && ! $this->demoAvailability->isAvailable()) {
            return new Collection;
        }

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
