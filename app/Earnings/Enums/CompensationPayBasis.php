<?php

declare(strict_types=1);

namespace App\Earnings\Enums;

/**
 * How an instructor's agreed compensation amount is denominated.
 * COMPENSATION basis, never settlement frequency — an hourly instructor
 * may be settled weekly, a monthly instructor may withdraw after the
 * monthly earning releases. Amounts are decided by administrators per
 * instructor; nothing here derives from student pricing.
 */
enum CompensationPayBasis: string
{
    case Hourly = 'hourly';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Hourly => 'Hourly (per 60 eligible teaching minutes)',
            self::Daily => 'Daily (fixed per payable day)',
            self::Weekly => 'Weekly (fixed per ISO Mon–Sun week)',
            self::Monthly => 'Monthly (fixed per calendar month)',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Hourly => 'Hourly',
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
        };
    }

    /** Lesson-triggered earning creation applies to hourly only. */
    public function isLessonBased(): bool
    {
        return $this === self::Hourly;
    }

    /** Accrued by the scheduled periodic sweep, one earning per closed period. */
    public function isPeriodic(): bool
    {
        return ! $this->isLessonBased();
    }
}
