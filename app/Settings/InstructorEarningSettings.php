<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class InstructorEarningSettings extends Settings
{
    /** Platform-wide switch — false stops all automatic earning creation. */
    public bool $earnings_enabled;

    /** 'percentage' | 'fixed' — global default; per-instructor/subject rules are a future phase. */
    public string $default_calculation_type;

    /** Instructor share of the student paid amount, 0–100. */
    public float $default_percentage;

    /** Fixed earning in minor units — used for fixed type and as the free/demo lesson rate. */
    public ?int $default_fixed_amount_minor;

    /** Currency for fixed earnings when the booking has none (free/demo lessons). */
    public ?string $default_currency_code;

    /** Days after lesson completion before an earning may be released. */
    public int $hold_days;

    /** Gates the instructor-earnings:release sweep. */
    public bool $auto_release_enabled;

    /** Minimum batch total; null = no minimum. */
    public ?int $minimum_settlement_amount_minor;

    /** 'manual' | 'weekly' | 'monthly' — informational this phase; batches are always admin-created. */
    public string $settlement_frequency;

    public static function group(): string
    {
        return 'instructor_earnings';
    }
}
