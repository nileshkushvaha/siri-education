<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\BookingTypeRepositoryInterface;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\Contracts\TeacherCandidateRepositoryInterface;
use App\Booking\DTOs\AssignmentCriteriaData;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\DTOs\RecurrenceData;
use App\Booking\DTOs\RecurringBookingResult;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\BookingStatus;
use App\Booking\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Student flow = teacher choice + recurrence on top of the core
 * engine. Every occurrence goes through BookingService::request, so
 * window rules, duplicates, availability, buffer, daily caps, locks,
 * events, and notifications behave exactly like every other flow.
 */
final class StudentBookingService implements StudentBookingServiceInterface
{
    public function __construct(
        private readonly BookingServiceInterface $bookings,
        private readonly BookingRepositoryInterface $repository,
        private readonly BookingTypeRepositoryInterface $types,
        private readonly TeacherCandidateRepositoryInterface $teachers,
    ) {}

    public function availableTeachers(string $typeKey, string $subject, int $grade): Collection
    {
        $type = $this->types->requireActiveByKey($typeKey);

        return $this->teachers->eligible(new AssignmentCriteriaData(
            typeKey: $typeKey,
            subject: $subject,
            grade: $grade,
            startsAt: CarbonImmutable::now()->addDay(),
            durationMinutes: $type->duration_minutes,
        ));
    }

    public function previousTeachers(User $student): Collection
    {
        return $this->repository->previousHostsForAttendee($student->id);
    }

    public function upcomingClasses(User $student): Collection
    {
        return $this->repository->upcomingForUser($student->id);
    }

    public function bookingHistory(User $student, int $perPage = 15, ?BookingStatus $status = null): LengthAwarePaginator
    {
        return $this->repository->paginatedForUser($student->id, $perPage, $status);
    }

    public function paymentHistory(User $student, int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->paginatedPaymentsForUser($student->id, $perPage);
    }

    public function attendanceStats(User $student): object
    {
        return $this->repository->attendanceStatsForUser($student->id);
    }

    public function attendanceHistory(User $student, int $limit = 50): Collection
    {
        return $this->repository->attendanceHistoryForUser($student->id, $limit);
    }

    public function progressStats(User $student): object
    {
        return $this->repository->progressStatsForUser($student->id);
    }

    public function subjectBreakdown(User $student): Collection
    {
        return $this->repository->subjectBreakdownForUser($student->id);
    }

    public function book(StudentBookingData $data): Booking
    {
        return $this->bookOccurrence($data, $data->startsAt);
    }

    public function bookRecurring(StudentBookingData $data, RecurrenceData $recurrence): RecurringBookingResult
    {
        $occurrences = max(1, min($recurrence->occurrences, RecurrenceData::MAX_OCCURRENCES));
        $interval = max(1, $recurrence->intervalWeeks);
        $groupId = (string) Str::uuid();

        $booked = new Collection;
        $failures = [];

        for ($i = 0; $i < $occurrences; $i++) {
            $startsAt = $data->startsAt->addWeeks($i * $interval);

            try {
                $booked->push($this->bookOccurrence($data, $startsAt, ['recurring_group' => $groupId]));
            } catch (BookingException $e) {
                $failures[$startsAt->toIso8601String()] = $e->getMessage();
            }
        }

        if ($booked->isEmpty()) {
            throw new BookingException('None of the requested sessions could be booked: '.implode(' ', $failures));
        }

        return new RecurringBookingResult($groupId, $booked, $failures);
    }

    /** @param array<string, mixed> $extraMeta */
    private function bookOccurrence(StudentBookingData $data, CarbonImmutable $startsAt, array $extraMeta = []): Booking
    {
        $type = $this->types->requireActiveByKey($data->typeKey);

        $this->assertTeacherBookable($data);

        return $this->bookings->request(new CreateBookingData(
            typeKey: $data->typeKey,
            attendeeId: $data->studentId,
            hostId: $data->teacherId,
            startsAt: $startsAt,
            durationMinutes: $type->duration_minutes,
            timezone: $data->timezone,
            notes: $data->notes,
            meta: array_filter([
                'subject' => $data->subject,
                'grade' => $data->grade,
                ...$extraMeta,
            ]),
        ));
    }

    private function assertTeacherBookable(StudentBookingData $data): void
    {
        if ($data->subject !== null && $data->grade !== null) {
            $eligible = $this->teachers->isEligible($data->teacherId, new AssignmentCriteriaData(
                typeKey: $data->typeKey,
                subject: $data->subject,
                grade: $data->grade,
                startsAt: $data->startsAt,
                durationMinutes: 0,
            ));

            if (! $eligible) {
                throw new BookingException('This teacher does not teach the selected subject and grade.');
            }

            return;
        }

        if (! $this->teachers->isApprovedTeacher($data->teacherId)) {
            throw new BookingException('This teacher is not currently accepting bookings.');
        }
    }
}
