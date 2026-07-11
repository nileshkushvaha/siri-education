<?php

declare(strict_types=1);

namespace App\Earnings\Enums;

enum EarningCalculationType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage of student price',
            self::Fixed => 'Fixed amount',
            self::Manual => 'Manual (admin-entered)',
        };
    }
}
