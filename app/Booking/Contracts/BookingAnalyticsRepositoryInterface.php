<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Aggregate queries for booking analytics. Every method is a single
 * database round-trip (GROUP BY / conditional aggregation) — no
 * per-row iteration. Periods filter on created_at unless noted.
 */
interface BookingAnalyticsRepositoryInterface
{
    /** @return object{total: int, demo_requests: int, cancelled: int, completed: int, revenue: string|null, refunded: string|null} */
    public function overview(CarbonImmutable $from, CarbonImmutable $to): object;

    /**
     * Demo→paid funnel: distinct demo bookers in the period, and how
     * many of them created a paid booking afterwards (any time).
     *
     * @return object{demo_bookers: int, converted: int}
     */
    public function conversion(CarbonImmutable $from, CarbonImmutable $to): object;

    /** @return Collection<int, object{day: string, bookings: int, revenue: string|null}> */
    public function trendPerDay(CarbonImmutable $from, CarbonImmutable $to): Collection;

    /** @return Collection<int, object{subject: string, bookings: int}> */
    public function popularSubjects(CarbonImmutable $from, CarbonImmutable $to, int $limit = 8): Collection;

    /** Session start hours (UTC), non-cancelled, by starts_at. @return Collection<int, object{hour: int, bookings: int}> */
    public function popularHours(CarbonImmutable $from, CarbonImmutable $to): Collection;

    /** Booked minutes per teacher (confirmed/completed, by starts_at). @return Collection<int, object{host_id: int, name: string, minutes: int}> */
    public function bookedMinutesPerTeacher(CarbonImmutable $from, CarbonImmutable $to, int $limit = 10): Collection;

    /**
     * Active weekly availability minutes per teacher.
     *
     * @param  list<int>  $teacherIds
     * @return Collection<int, object{teacher_id: int, minutes: int}>
     */
    public function weeklyAvailableMinutes(array $teacherIds): Collection;
}
