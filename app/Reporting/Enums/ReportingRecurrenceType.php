<?php

declare(strict_types=1);

namespace App\Reporting\Enums;

use App\Booking\Enums\RecurrenceFrequency;

/**
 * The reporting-filter recurrence dimension (Phase 18B §8). Value 1
 * bookings are either part of a recurrence group (`RecurrenceFrequency`
 * — `Daily`/`Weekly`, the source domain enum) or a one-off booking with
 * no recurrence group at all. `Single` is a reporting-layer concept
 * only — it does not duplicate `RecurrenceFrequency`, it names the
 * "no recurrence group" case that enum has no case for.
 */
enum ReportingRecurrenceType: string
{
    case Single = 'single';
    case Daily = 'daily';
    case Weekly = 'weekly';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Single (non-recurring)',
            self::Daily => 'Daily recurring',
            self::Weekly => 'Weekly recurring',
        };
    }

    public static function fromRecurrenceFrequency(?RecurrenceFrequency $frequency): self
    {
        return match ($frequency) {
            null => self::Single,
            RecurrenceFrequency::Daily => self::Daily,
            RecurrenceFrequency::Weekly => self::Weekly,
        };
    }
}
