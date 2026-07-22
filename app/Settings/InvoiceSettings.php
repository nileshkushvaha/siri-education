<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * SRS §14.22: "The numbering format should be configurable." Only the
 * prefix, the digit width of the sequence portion, and the template
 * itself are configurable — never the numbering algorithm (annual
 * scope, one allocation per invoice) or the invoice fields themselves.
 */
class InvoiceSettings extends Settings
{
    public string $number_prefix;

    /** Must contain the {sequence} token; may also use {prefix}/{year}. */
    public string $number_format;

    public int $sequence_digits;

    public static function group(): string
    {
        return 'invoice';
    }
}
