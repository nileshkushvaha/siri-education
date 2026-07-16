<?php

declare(strict_types=1);

namespace App\Reporting\ValueObjects;

use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Exceptions\InvalidReportingPeriodException;
use App\Reporting\Support\ReportingTimezoneResolver;
use Carbon\CarbonImmutable;

/**
 * An immutable reporting period (Phase 18B §6): a local calendar range
 * interpreted in an explicit reporting timezone, plus the derived UTC
 * query boundary. Query code must always use `$startUtc`/`$endUtcExclusive`
 * — a half-open interval `[start_utc, end_utc_exclusive)` — never the
 * local dates directly, and never a fragile `23:59:59` end-of-day
 * calculation (an exclusive next-instant boundary is DST-proof; a
 * literal "23:59:59" is not, on a day with a non-24-hour DST transition).
 */
final readonly class ReportingPeriod
{
    /** Conservative explicit limit for a synchronous Version 1 report screen (§6) — not a limit for a future queued export. */
    public const int MAX_CUSTOM_RANGE_DAYS = 366;

    private function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public string $timezone,
        public CarbonImmutable $startUtc,
        public CarbonImmutable $endUtcExclusive,
        public ReportingPeriodPreset $preset,
        public string $label,
    ) {}

    public static function forPreset(ReportingPeriodPreset $preset, ?string $timezone = null, ?CarbonImmutable $now = null): self
    {
        if ($preset === ReportingPeriodPreset::Custom) {
            throw new InvalidReportingPeriodException('Use ReportingPeriod::custom() for the Custom preset — it requires explicit start/end dates.');
        }

        $tz = ReportingTimezoneResolver::resolve($timezone);
        $reference = ($now ?? CarbonImmutable::now())->setTimezone($tz);

        [$start, $end] = match ($preset) {
            ReportingPeriodPreset::Today => [
                $reference->startOfDay(),
                $reference->startOfDay()->addDay(),
            ],
            ReportingPeriodPreset::Yesterday => [
                $reference->subDay()->startOfDay(),
                $reference->startOfDay(),
            ],
            ReportingPeriodPreset::Last7Days => [
                $reference->startOfDay()->subDays(6),
                $reference->startOfDay()->addDay(),
            ],
            ReportingPeriodPreset::Last30Days => [
                $reference->startOfDay()->subDays(29),
                $reference->startOfDay()->addDay(),
            ],
            ReportingPeriodPreset::ThisMonth => [
                $reference->startOfMonth(),
                $reference->startOfMonth()->addMonthNoOverflow(),
            ],
            ReportingPeriodPreset::PreviousMonth => [
                $reference->subMonthNoOverflow()->startOfMonth(),
                $reference->startOfMonth(),
            ],
            ReportingPeriodPreset::Custom => throw new InvalidReportingPeriodException('Unreachable — guarded above.'),
        };

        return new self(
            start: $start,
            end: $end,
            timezone: $tz,
            startUtc: $start->utc(),
            endUtcExclusive: $end->utc(),
            preset: $preset,
            label: sprintf('%s (%s)', $preset->label(), $tz),
        );
    }

    /**
     * @param  CarbonImmutable|string  $startDate  A calendar date (time-of-day, if any, is discarded).
     * @param  CarbonImmutable|string  $endDate  A calendar date, inclusive — identical start/end dates span one full local day.
     *
     * @throws InvalidReportingPeriodException
     */
    public static function custom(CarbonImmutable|string $startDate, CarbonImmutable|string $endDate, ?string $timezone = null): self
    {
        $tz = ReportingTimezoneResolver::resolve($timezone);

        $start = ($startDate instanceof CarbonImmutable ? $startDate->setTimezone($tz) : CarbonImmutable::parse($startDate, $tz))->startOfDay();
        $endInclusive = ($endDate instanceof CarbonImmutable ? $endDate->setTimezone($tz) : CarbonImmutable::parse($endDate, $tz))->startOfDay();

        if ($endInclusive->lt($start)) {
            throw InvalidReportingPeriodException::endBeforeStart();
        }

        $requestedDays = (int) ($start->diffInDays($endInclusive) + 1);

        if ($requestedDays > self::MAX_CUSTOM_RANGE_DAYS) {
            throw InvalidReportingPeriodException::rangeTooWide($requestedDays, self::MAX_CUSTOM_RANGE_DAYS);
        }

        $end = $endInclusive->addDay(); // exclusive boundary — the day AFTER the given inclusive end date

        return new self(
            start: $start,
            end: $end,
            timezone: $tz,
            startUtc: $start->utc(),
            endUtcExclusive: $end->utc(),
            preset: ReportingPeriodPreset::Custom,
            label: sprintf(
                '%s – %s (%s)',
                $start->toDateString(),
                $endInclusive->toDateString(),
                $tz,
            ),
        );
    }
}
