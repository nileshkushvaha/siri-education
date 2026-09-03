<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Frontend-portal colour theme chosen by a student or instructor.
 * Light is the product default; null in storage resolves to Light.
 */
enum ThemePreference: string
{
    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';

    public const DEFAULT = self::Light;

    public function label(): string
    {
        return match ($this) {
            self::Light => 'Light',
            self::Dark => 'Dark',
            self::System => 'Match my device',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return array_combine(
            array_map(fn (self $c) => $c->value, self::cases()),
            array_map(fn (self $c) => $c->label(), self::cases()),
        );
    }
}
