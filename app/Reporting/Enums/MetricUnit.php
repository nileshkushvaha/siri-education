<?php

declare(strict_types=1);

namespace App\Reporting\Enums;

/** The unit a metric's value is expressed in (SRS §11). */
enum MetricUnit: string
{
    case Count = 'count';
    case Percentage = 'percentage';
    case Duration = 'duration';
    case MoneyMinor = 'money_minor';

    public function label(): string
    {
        return match ($this) {
            self::Count => 'Count',
            self::Percentage => 'Percentage',
            self::Duration => 'Duration',
            self::MoneyMinor => 'Money (minor units)',
        };
    }
}
