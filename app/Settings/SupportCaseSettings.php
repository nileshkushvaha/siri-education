<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * SRS §25.12: reference number example "SUP-2026-000123". Only the
 * prefix, digit width, and template are configurable — never the
 * numbering algorithm (annual scope, one allocation per case).
 */
class SupportCaseSettings extends Settings
{
    public string $number_prefix;

    /** Must contain the {sequence} token; may also use {prefix}/{year}. */
    public string $number_format;

    public int $sequence_digits;

    public static function group(): string
    {
        return 'support_case';
    }
}
