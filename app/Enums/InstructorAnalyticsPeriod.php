<?php

declare(strict_types=1);

namespace App\Enums;

use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Support\ReportingTimezoneResolver;
use App\Reporting\ValueObjects\ReportingPeriod;
use Carbon\CarbonImmutable;

/**
 * Instructor Analytics period filter. Every bounded case
 * is computed by the existing App\Reporting\ValueObjects\ReportingPeriod
 * — never a second date-boundary implementation. Last90Days/ThisYear
 * reuse ReportingPeriod::custom() (the admin preset list doesn't cover
 * them); AllTime has no lower bound to compute, so it stays a null
 * period and callers skip the date filter entirely for that case.
 */
enum InstructorAnalyticsPeriod: string
{
    case Last7Days = 'last_7_days';
    case Last30Days = 'last_30_days';
    case Last90Days = 'last_90_days';
    case ThisYear = 'this_year';
    case AllTime = 'all_time';

    public function label(): string
    {
        return match ($this) {
            self::Last7Days => 'Last 7 days',
            self::Last30Days => 'Last 30 days',
            self::Last90Days => 'Last 90 days',
            self::ThisYear => 'This year',
            self::AllTime => 'All time',
        };
    }

    /** Null only for AllTime — every other case is a real ReportingPeriod. */
    public function toReportingPeriod(?string $timezone = null): ?ReportingPeriod
    {
        $tz = ReportingTimezoneResolver::resolve($timezone);
        $now = CarbonImmutable::now($tz);

        return match ($this) {
            self::Last7Days => ReportingPeriod::forPreset(ReportingPeriodPreset::Last7Days, $tz),
            self::Last30Days => ReportingPeriod::forPreset(ReportingPeriodPreset::Last30Days, $tz),
            self::Last90Days => ReportingPeriod::custom($now->subDays(89)->toDateString(), $now->toDateString(), $tz),
            self::ThisYear => ReportingPeriod::custom($now->startOfYear()->toDateString(), $now->toDateString(), $tz),
            self::AllTime => null,
        };
    }
}
