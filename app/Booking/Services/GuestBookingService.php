<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\AvailabilityServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\BookingTypeRepositoryInterface;
use App\Booking\Contracts\GuestBookingServiceInterface;
use App\Booking\Contracts\TeacherAssignmentServiceInterface;
use App\Booking\Contracts\TeacherCandidateRepositoryInterface;
use App\Booking\DTOs\AssignmentCriteriaData;
use App\Booking\DTOs\AvailabilityQueryData;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\DTOs\GuestBookingData;
use App\Booking\DTOs\RescheduleBookingData;
use App\Booking\DTOs\TimeSlotData;
use App\Booking\Enums\BookingActor;
use App\Booking\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

final class GuestBookingService implements GuestBookingServiceInterface
{
    /** Spam guard: max active upcoming bookings per guest email. */
    private const int MAX_ACTIVE_PER_EMAIL = 3;

    public function __construct(
        private readonly BookingServiceInterface $bookings,
        private readonly BookingRepositoryInterface $repository,
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
    ): Collection {
        $type = $this->types->requireActiveByKey($typeKey);
        $totalDays = (int) $from->startOfDay()->diffInDays($to->endOfDay()) + 1;

        // Stream per teacher and keep only the date strings — never the
        // slot objects — and stop as soon as every day is covered.
        $found = [];

        foreach ($this->eligibleTeachers($typeKey, $subject, $grade, $from, $type->duration_minutes) as $teacher) {
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
    ): Collection {
        $from = $date->setTimezone($timezone)->startOfDay();

        return $this
            ->slotsAcrossTeachers($typeKey, $subject, $grade, $from, $from->addDay(), $timezone)
            ->unique(fn (TimeSlotData $slot): int => $slot->startsAt->getTimestamp())
            ->sortBy(fn (TimeSlotData $slot): int => $slot->startsAt->getTimestamp())
            ->values();
    }

    public function book(GuestBookingData $data): Booking
    {
        if ($this->repository->activeUpcomingCountForGuestEmail($data->guestEmail) >= self::MAX_ACTIVE_PER_EMAIL) {
            throw new BookingException(sprintf(
                'This email already has %d active bookings. Please attend or cancel one first.',
                self::MAX_ACTIVE_PER_EMAIL,
            ));
        }

        $type = $this->types->requireActiveByKey($data->typeKey);

        $teacher = $this->assigner->assign(new AssignmentCriteriaData(
            typeKey: $data->typeKey,
            subject: $data->subject,
            grade: $data->grade,
            startsAt: $data->startsAt,
            durationMinutes: $type->duration_minutes,
            timezone: $data->timezone,
        ));

        return $this->bookings->request(new CreateBookingData(
            typeKey: $data->typeKey,
            attendeeId: auth()->id(),
            hostId: $teacher->id,
            startsAt: $data->startsAt,
            durationMinutes: $type->duration_minutes,
            timezone: $data->timezone,
            notes: $data->notes,
            meta: ['subject' => $data->subject, 'grade' => $data->grade],
            guestName: $data->guestName,
            guestEmail: $data->guestEmail,
            guestPhone: $data->guestPhone,
        ));
    }

    public function findForGuest(string $reference, string $token): Booking
    {
        $booking = $this->repository->findByReference($reference);

        // The stored token is a SHA-256 hash; hash the presented one first.
        if ($booking === null
            || $booking->manage_token === null
            || ! hash_equals($booking->manage_token, hash('sha256', $token))) {
            throw (new ModelNotFoundException)->setModel(Booking::class);
        }

        return $booking;
    }

    public function cancel(Booking $booking, ?string $reason = null): Booking
    {
        return $this->bookings->cancel($booking, new CancelBookingData(BookingActor::Attendee, $reason));
    }

    public function reschedule(Booking $booking, CarbonImmutable $startsAt, ?string $reason = null): Booking
    {
        return $this->bookings->reschedule(
            $booking,
            new RescheduleBookingData($startsAt, BookingActor::Attendee, reason: $reason),
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
    ): Collection {
        $type = $this->types->requireActiveByKey($typeKey);

        return $this
            ->eligibleTeachers($typeKey, $subject, $grade, $from, $type->duration_minutes)
            ->flatMap(fn (User $teacher): Collection => $this->availability->slots(
                new AvailabilityQueryData($teacher->id, $typeKey, $from, $to, $timezone),
            ));
    }

    /** @return Collection<int, User> */
    private function eligibleTeachers(string $typeKey, string $subject, int $grade, CarbonImmutable $startsAt, int $duration): Collection
    {
        return $this->candidates->eligible(
            new AssignmentCriteriaData($typeKey, $subject, $grade, $startsAt, $duration),
        );
    }
}
