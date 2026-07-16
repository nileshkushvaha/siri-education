<?php

declare(strict_types=1);

namespace App\Reporting\Enums;

/** Standard reporting-period presets (Phase 18B §6). `Custom` carries an explicit start/end date pair. */
enum ReportingPeriodPreset: string
{
    case Today = 'today';
    case Yesterday = 'yesterday';
    case Last7Days = 'last_7_days';
    case Last30Days = 'last_30_days';
    case ThisMonth = 'this_month';
    case PreviousMonth = 'previous_month';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Today => 'Today',
            self::Yesterday => 'Yesterday',
            self::Last7Days => 'Last 7 days',
            self::Last30Days => 'Last 30 days',
            self::ThisMonth => 'This month',
            self::PreviousMonth => 'Previous month',
            self::Custom => 'Custom range',
        };
    }
}
