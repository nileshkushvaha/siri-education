<?php

declare(strict_types=1);

namespace App\Earnings\Enums;

/**
 * How an earning's amount was derived, stored immutably per row. Every
 * type is agreement-based (Phase 14.2) — instructor compensation is
 * never calculated from the student-facing price, and the commission-era
 * types (percentage / global fixed) were removed entirely in Phase 14.4
 * after verifying zero historical rows ever used them.
 */
enum EarningCalculationType: string
{
    case Hourly = 'hourly';
    case Periodic = 'periodic';
    case DemoFixed = 'demo_fixed';

    /** GAP-008 (SRS §15.18) — a fixed bonus for converting a demo to a paid lesson, never derived from the compensation agreement. */
    case DemoConversionIncentive = 'demo_conversion_incentive';

    /** Reserved for a future audited manual-adjustment flow — no code path produces it yet. */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Hourly => 'Hourly agreement rate',
            self::Periodic => 'Periodic agreement (daily/weekly/monthly)',
            self::DemoFixed => 'Demo lesson fixed compensation',
            self::DemoConversionIncentive => 'Demo-to-paid conversion incentive',
            self::Manual => 'Manual (admin-entered)',
        };
    }
}
