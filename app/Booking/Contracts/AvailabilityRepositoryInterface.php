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

    /** The given moment falls on an organisation-wide holiday. */
    public function isHoliday(CarbonImmutable $date): bool;

    /** @return Collection<int, string> holiday dates (Y-m-d) within [$from, $to] */
    public function holidayDatesBetween(CarbonImmutable $from, CarbonImmutable $to): Collection;
}
