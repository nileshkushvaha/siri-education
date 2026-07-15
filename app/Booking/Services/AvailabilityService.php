<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\AvailabilityRepositoryInterface;
use App\Booking\Contracts\AvailabilityServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\BookingTypeRepositoryInterface;
use App\Booking\Contracts\TeacherCandidateRepositoryInterface;
use App\Booking\DTOs\AvailabilityQueryData;
use App\Booking\DTOs\TimeSlotData;
use App\Booking\Exceptions\SlotUnavailableException;
use App\Booking\Validation\Rules\BookingWindowRule;
use App\Models\Booking;
use App\Models\TeacherUnavailability;
use App\Settings\BookingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Dynamic slot engine — nothing is precomputed or stored. Slots are
 * derived on demand from weekly windows minus leave, holidays,
 * existing bookings (with buffer), the bookable window, and the
 * teacher's daily cap. All storage is UTC; results are returned in the
 * requested timezone.
 */
final class AvailabilityService implements AvailabilityServiceInterface
{
    public function __construct(
        private readonly AvailabilityRepositoryInterface $availability,
        private readonly BookingRepositoryInterface $bookings,
        private readonly BookingTypeRepositoryInterface $types,
        private readonly BookingWindowRule $window,
        private readonly SlotGenerator $generator,
        private readonly BookingSettings $settings,
        private readonly TeacherCandidateRepositoryInterface $teachers,
    ) {}

    public function slots(AvailabilityQueryData $query): Collection
    {
        if (! $this->teachers->isApprovedTeacher($query->instructorId)) {
            return new Collection;
        }

        $type = $this->types->requireActiveByKey($query->typeKey);
        $duration = $type->duration_minutes;
        $buffer = $type->buffer_minutes;
        $sharesSlot = $type->max_attendees !== 1;

        $from = $query->from->utc();
        $to = $query->to->utc();

        $windows = $this->availability->windowsFor($query->instructorId, $from, $to);
        $holidays = $this->availability->holidayDatesBetween($from, $to);
        $blackouts = $this->availability
            ->blackoutsFor($query->instructorId, $from, $to)
            ->map(fn (TeacherUnavailability $b): array => ['starts_at' => $b->starts_at, 'ends_at' => $b->ends_at]);
        $bookings = $this->bookings->activeBetween($query->instructorId, $from->subMinutes($buffer), $to->addMinutes($buffer));

        $maxDaily = $this->settings->max_daily_bookings_per_teacher;
        $bookedPerDay = $bookings->countBy(fn (Booking $b): string => $b->starts_at->toDateString());

        $slots = new Collection;

        foreach ($this->generator->candidates($windows, $duration, $buffer) as $candidate) {
            [$start, $end] = [$candidate['starts_at'], $candidate['ends_at']];

            if (! $this->window->isWithinWindow($start)) {
                continue;
            }

            if ($holidays->contains($start->toDateString())) {
                continue;
            }

            if ($maxDaily !== null && ($bookedPerDay[$start->toDateString()] ?? 0) >= $maxDaily) {
                continue;
            }

            if ($this->generator->conflicts($start, $end, $blackouts)) {
                continue;
            }

            $overlapping = $bookings->filter(
                fn (Booking $b): bool => $b->starts_at->lessThan($end->addMinutes($buffer))
                    && $b->ends_at->greaterThan($start->subMinutes($buffer)),
            );

            $sameSlotSameType = $overlapping->filter(
                fn (Booking $b): bool => $b->booking_type_id === $type->id
                    && $b->starts_at->equalTo($start)
                    && $b->ends_at->equalTo($end),
            );

            if ($sharesSlot) {
                // Foreign overlaps block; same-type same-slot bookings share.
                if ($overlapping->count() > $sameSlotSameType->count()) {
                    continue;
                }

                $remaining = $type->max_attendees === null
                    ? null
                    : $type->max_attendees - $sameSlotSameType->count();

                if ($remaining !== null && $remaining < 1) {
                    continue;
                }
            } else {
                if ($overlapping->isNotEmpty()) {
                    continue;
                }

                $remaining = 1;
            }

            $slots->push(new TimeSlotData(
                instructorId: $query->instructorId,
                startsAt: $start->setTimezone($query->timezone),
                endsAt: $end->setTimezone($query->timezone),
                remainingCapacity: $remaining,
            ));
        }

        return $slots->values();
    }

    public function ensureAvailable(
        int $instructorId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?string $ignoreBookingId = null,
        ?string $sharedSlotTypeKey = null,
        int $bufferMinutes = 0,
    ): void {
        $startsAt = $startsAt->utc();
        $endsAt = $endsAt->utc();

        // Re-verified here (not just by the caller before the host lock) so a
        // teacher deactivated/rejected between the caller's eligibility check
        // and lock acquisition can never still be booked — this is the final
        // truth check, run both fast-fail and inside the race-safe lock.
        if (! $this->teachers->isApprovedTeacher($instructorId)) {
            throw SlotUnavailableException::for($instructorId, $startsAt);
        }

        if (! $this->availability->windowCovers($instructorId, $startsAt, $endsAt)) {
            throw SlotUnavailableException::for($instructorId, $startsAt);
        }

        if ($this->availability->isHoliday($startsAt)) {
            throw SlotUnavailableException::for($instructorId, $startsAt);
        }

        if ($this->availability->hasBlackout($instructorId, $startsAt, $endsAt)) {
            throw SlotUnavailableException::for($instructorId, $startsAt);
        }

        if ($this->bookings->hasOverlap($instructorId, $startsAt, $endsAt, $ignoreBookingId, $sharedSlotTypeKey, $bufferMinutes)) {
            throw SlotUnavailableException::for($instructorId, $startsAt);
        }

        $maxDaily = $this->settings->max_daily_bookings_per_teacher;

        if ($maxDaily !== null
            && $this->bookings->activeCountForDay($instructorId, $startsAt, $ignoreBookingId) >= $maxDaily) {
            throw SlotUnavailableException::for($instructorId, $startsAt);
        }
    }
}
