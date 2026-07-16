<?php

declare(strict_types=1);

namespace App\Reporting\Enums;

/**
 * How a ratio/average metric behaves when its denominator is zero
 * (Phase 18B §11) — every metric definition must pick one explicitly,
 * mirroring the codebase-wide convention that a zero-review instructor
 * shows `averageRating: null`, never a fabricated `0`
 * (`InstructorRatingAggregateService::summaryFor()`).
 */
enum ZeroDenominatorPolicy: string
{
    case ReturnNull = 'return_null';
    case ReturnZero = 'return_zero';
    case Omit = 'omit';

    public function label(): string
    {
        return match ($this) {
            self::ReturnNull => 'Return null (no fabricated value)',
            self::ReturnZero => 'Return zero',
            self::Omit => 'Omit the metric entirely',
        };
    }
}
