<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\BookingAnalyticsRepositoryInterface;
use App\Booking\Contracts\BookingAnalyticsServiceInterface;
use App\Booking\DTOs\BookingKpiData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;

final class BookingAnalyticsService implements BookingAnalyticsServiceInterface
{
    /** Seconds. Analytics tolerate staleness; dashboards reload cheaply. */
    private const int CACHE_TTL = 300;

    public function __construct(
        private readonly BookingAnalyticsRepositoryInterface $analytics,
        private readonly CacheRepository $cache,
    ) {}

    public function kpis(CarbonImmutable $from, CarbonImmutable $to): BookingKpiData
    {
        return $this->remember('kpis', $from, $to, function () use ($from, $to): BookingKpiData {
            $overview = $this->analytics->overview($from, $to);
            $conversion = $this->analytics->conversion($from, $to);

            $total = (int) $overview->total;
            $cancelled = (int) $overview->cancelled;
            $demoBookers = (int) $conversion->demo_bookers;
            $converted = (int) $conversion->converted;

            return new BookingKpiData(
                totalBookings: $total,
                demoRequests: (int) $overview->demo_requests,
                demoBookers: $demoBookers,
                convertedBookers: $converted,
                conversionRate: $demoBookers > 0 ? round($converted / $demoBookers * 100, 1) : 0.0,
                revenue: number_format((float) ($overview->revenue ?? 0), 2, '.', ''),
                refunded: number_format((float) ($overview->refunded ?? 0), 2, '.', ''),
                cancelled: $cancelled,
                cancellationRate: $total > 0 ? round($cancelled / $total * 100, 1) : 0.0,
                completed: (int) $overview->completed,
            );
        });
    }

    public function trend(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->remember('trend', $from, $to, function () use ($from, $to): Collection {
            $rows = $this->analytics->trendPerDay($from, $to)->keyBy('day');

            $days = new Collection;
            for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
                $key = $day->toDateString();
                $days->push([
                    'day' => $key,
                    'bookings' => (int) ($rows[$key]->bookings ?? 0),
                    'revenue' => (float) ($rows[$key]->revenue ?? 0),
                ]);
            }

            return $days;
        });
    }

    public function popularSubjects(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->remember('subjects', $from, $to, fn (): Collection => $this->analytics
            ->popularSubjects($from, $to)
            ->map(fn (object $row): array => [
                'subject' => (string) $row->subject,
                'bookings' => (int) $row->bookings,
            ]));
    }

    public function popularTimeSlots(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->remember('hours', $from, $to, function () use ($from, $to): Collection {
            $rows = $this->analytics->popularHours($from, $to)->keyBy('hour');

            return collect(range(0, 23))->map(fn (int $hour): array => [
                'hour' => $hour,
                'bookings' => (int) ($rows[$hour]->bookings ?? 0),
            ]);
        });
    }

    public function teacherUtilization(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->remember('utilization', $from, $to, function () use ($from, $to): Collection {
            $booked = $this->analytics->bookedMinutesPerTeacher($from, $to);
            $weekly = $this->analytics
                ->weeklyAvailableMinutes($booked->pluck('instructor_id')->all())
                ->keyBy('teacher_id');

            // Approximation: weekly schedule × weeks in period, ignoring
            // leave/holidays — good enough for a utilization ranking.
            $weeks = max($from->diffInDays($to) / 7, 1 / 7);

            return $booked->map(function (object $row) use ($weekly, $weeks): array {
                $available = (float) ($weekly[$row->instructor_id]->minutes ?? 0) * $weeks;

                return [
                    'teacher' => (string) $row->name,
                    'booked_hours' => round(((int) $row->minutes) / 60, 1),
                    'available_hours' => round($available / 60, 1),
                    'utilization' => $available > 0.0
                        ? min(100.0, round(((int) $row->minutes) / $available * 100, 1))
                        : 0.0,
                ];
            })->sortByDesc('utilization')->values();
        });
    }

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    private function remember(string $key, CarbonImmutable $from, CarbonImmutable $to, \Closure $callback): mixed
    {
        return $this->cache->remember(
            sprintf('booking-analytics:%s:%s:%s', $key, $from->timestamp, $to->timestamp),
            self::CACHE_TTL,
            $callback,
        );
    }
}
