<?php

declare(strict_types=1);

namespace App\Reporting\Enums;

/**
 * The reporting-filter booking-type dimension (Phase 18B §8/§18B baseline).
 * Values mirror `App\Booking\Registry\BookingTypeRegistry`'s exact
 * approved Version 1 keys (`tests/Architecture/BookingTypeScopeGuardTest`)
 * — this is a filter-layer enum, not a duplicate source of truth; an
 * unknown/removed booking-type key must fail (`tryFrom()` returns
 * `null`) rather than ever silently falling back to `PaidOneToOne`.
 */
enum ReportingBookingType: string
{
    case FreeDemo = 'free_demo';
    case PaidOneToOne = 'paid_one_to_one';

    public function label(): string
    {
        return match ($this) {
            self::FreeDemo => 'Free demo',
            self::PaidOneToOne => 'Paid 1:1',
        };
    }
}
