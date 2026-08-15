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
use App\Support\Timezone\LocalDay;
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

        $from = $query->from->utc();
        $to = $query->to->utc();

        $windows = $this->availability->windowsFor($query->instructorId, $from, $to);
        $holidays = $this->availability->holidayDatesBetween($from, $to);
        $blackouts = $this->availability
            ->blackoutsFor($query->instructorId, $from, $to)
            ->map(fn (TeacherUnavailability $b): array => ['starts_at' => $b->starts_at, 'ends_at' => $b->ends_at]);
        $bookings = $this->bookings->activeBetween($query->instructorId, $from->subMinutes($buffer), $to->addMinutes($buffer));

        // TZ-2A: every instructor-local-day rule below is judged in ONE
        // resolved calendar, shared with ensureAvailable() so a slot
        // this method offers can never be rejected there for being on a
        // different day.
        $calendarTimezone = $this->availability->calendarTimezoneFor($query->instructorId);

        $maxDaily = $this->settings->max_daily_bookings_per_teacher;
        $bookedPerDay = $bookings->countBy(
            fn (Booking $b): string => LocalDay::containing($b->starts_at, $calendarTimezone)->date,
        );

        $slots = new Collection;

        foreach ($this->generator->candidates($windows, $duration, $buffer) as $candidate) {
            [$start, $end] = [$candidate['starts_at'], $candidate['ends_at']];

            if (! $this->window->isWithinWindow($start)) {
                continue;
            }

            // $start is a UTC instant, so `$start->toDateString()` — what
            // both checks used to do — asks "which day is this in UTC?".
            // For an instructor in Australia/Sydney a morning slot
            // carries the PREVIOUS UTC date and for one in
            // America/Los_Angeles an evening slot carries the NEXT, so
            // holidays landed on the wrong day in both directions and
            // one local day's bookings were split across two cap buckets
            // (TZ-AUD-005 / TZ-AUD-006).
            $localDate = LocalDay::containing($start, $calendarTimezone)->date;

            if ($holidays->contains($localDate)) {
                continue;
            }

            if ($maxDaily !== null && ($bookedPerDay[$localDate] ?? 0) >= $maxDaily) {
                continue;
            }

            if ($this->generator->conflicts($start, $end, $blackouts)) {
                continue;
            }

            $overlapping = $bookings->filter(
                fn (Booking $b): bool => $b->starts_at->lessThan($end->addMinutes($buffer))
                    && $b->ends_at->greaterThan($start->subMinutes($buffer)),
            );

            if ($overlapping->isNotEmpty()) {
                continue;
            }

            $slots->push(new TimeSlotData(
                instructorId: $query->instructorId,
                startsAt: $start->setTimezone($query->timezone),
                endsAt: $end->setTimezone($query->timezone),
            ));
        }

        return $slots->values();
    }

    public function ensureAvailable(
        int $instructorId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?string $ignoreBookingId = null,
        int $bufferMinutes = 0,
    ): void {
        $startsAt = $startsAt->utc();
        $endsAt = $endsAt->utc();

        // TZ-2A: the SAME resolved calendar slots() used. Both paths ask
        // one method for it, so the enforcement check can never disagree
        // with the offer about which instructor-local day a booking
        // falls on.
        $calendarTimezone = $this->availability->calendarTimezoneFor($instructorId);

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

        if ($this->availability->isHoliday($startsAt, $calendarTimezone)) {
            throw SlotUnavailableException::for($instructorId, $startsAt);
        }

        if ($this->availability->hasBlackout($instructorId, $startsAt, $endsAt)) {
            throw SlotUnavailableException::for($instructorId, $startsAt);
        }

        if ($this->bookings->hasOverlap($instructorId, $startsAt, $endsAt, $ignoreBookingId, $bufferMinutes)) {
            throw SlotUnavailableException::for($instructorId, $startsAt);
        }

        $maxDaily = $this->settings->max_daily_bookings_per_teacher;

        if ($maxDaily !== null
            && $this->bookings->activeCountForDay(
                $instructorId,
                LocalDay::containing($startsAt, $calendarTimezone),
                $ignoreBookingId,
            ) >= $maxDaily) {
            throw SlotUnavailableException::for($instructorId, $startsAt);
        }
    }
}
