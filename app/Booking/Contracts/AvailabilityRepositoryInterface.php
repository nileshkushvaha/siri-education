<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Models\TeacherUnavailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Persistence for teacher availability windows and blackouts.
 */
interface AvailabilityRepositoryInterface
{
    /**
     * Concrete availability windows for a teacher within a date range —
     * weekly rows expanded to datetimes, before bookings are subtracted.
     *
     * @return Collection<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    public function windowsFor(int $teacherId, CarbonImmutable $from, CarbonImmutable $to): Collection;

    /** An active weekly window fully covers [$startsAt, $endsAt) (same day). */
    public function windowCovers(int $teacherId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool;

    /** A blackout intersects [$startsAt, $endsAt). */
    public function hasBlackout(int $teacherId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool;

    /** @return Collection<int, TeacherUnavailability> blackouts intersecting [$from, $to) */
    public function blackoutsFor(int $teacherId, CarbonImmutable $from, CarbonImmutable $to): Collection;

    /**
     * TZ-2A: the ONE definition of "which calendar does this
     * instructor's day belong to?", used by every instructor-local-day
     * rule — currently the holiday exclusion and the daily booking cap,
     * on BOTH the slot-generation and the booking-enforcement path, so
     * the two can never disagree about which day a slot is on.
     *
     * Rule-timezone first (the business-owned source
     * `teacher_availability.timezone`, already authoritative for
     * materializing windows), then the instructor's canonical user
     * timezone as the fallback.
     */
    public function calendarTimezoneFor(int $teacherId): string;

    /**
     * The given moment falls on an organisation-wide holiday, judged by
     * the LOCAL calendar date in $timezone — never the UTC date of the
     * instant (TZ-AUD-005).
     */
    public function isHoliday(CarbonImmutable $date, string $timezone): bool;

    /**
     * Holiday dates (Y-m-d) covering the local days that [$from, $to)
     * touches in $timezone. The range is widened by a day on each side,
     * because a UTC window's edges can fall on a local date outside it.
     *
     * @return Collection<int, string>
     */
    public function holidayDatesBetween(CarbonImmutable $from, CarbonImmutable $to): Collection;
}
