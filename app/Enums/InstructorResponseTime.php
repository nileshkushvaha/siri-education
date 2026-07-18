<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Admin-set expectation shown on the public profile ("Usually responds
 * within 2 hours") — never a calculated/measured value. Backs
 * UserProfile::response_time_minutes (nullable — null means no
 * expectation has been set, and the public profile shows nothing).
 */
enum InstructorResponseTime: int
{
    case FifteenMinutes = 15;
    case ThirtyMinutes = 30;
    case OneHour = 60;
    case TwoHours = 120;
    case TwentyFourHours = 1440;
    case FortyEightHours = 2880;

    public function label(): string
    {
        return match ($this) {
            self::FifteenMinutes => '15 minutes',
            self::ThirtyMinutes => '30 minutes',
            self::OneHour => '1 hour',
            self::TwoHours => '2 hours',
            self::TwentyFourHours => '24 hours',
            self::FortyEightHours => '48 hours',
        };
    }

    public function publicLabel(): string
    {
        return "Usually responds within {$this->label()}";
    }

    /** @return array<int, string> Select-friendly [value => label] options. */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }
}
