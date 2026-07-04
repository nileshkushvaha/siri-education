<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\BookingKpiData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Cached analytics facade for dashboards, charts, and exports.
 * Results are cached briefly (see BookingAnalyticsService::CACHE_TTL)
 * — analytics tolerate slight staleness in exchange for cheap reloads.
 */
interface BookingAnalyticsServiceInterface
{
    public function kpis(CarbonImmutable $from, CarbonImmutable $to): BookingKpiData;

    /** @return Collection<int, array{day: string, bookings: int, revenue: float}> gap-filled per calendar day */
    public function trend(CarbonImmutable $from, CarbonImmutable $to): Collection;

    /** @return Collection<int, array{subject: string, bookings: int}> */
    public function popularSubjects(CarbonImmutable $from, CarbonImmutable $to): Collection;

    /** @return Collection<int, array{hour: int, bookings: int}> all 24 hours, gap-filled */
    public function popularTimeSlots(CarbonImmutable $from, CarbonImmutable $to): Collection;

    /** @return Collection<int, array{teacher: string, booked_hours: float, available_hours: float, utilization: float}> */
    public function teacherUtilization(CarbonImmutable $from, CarbonImmutable $to): Collection;
}
