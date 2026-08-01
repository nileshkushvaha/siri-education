<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Country and locale-switching behavior only. Timezone, language,
 * date/time format, and currency are GeneralSettings' concern — do not
 * re-add them here as "fallback_*" duplicates.
 */
class LocalizationSettings extends Settings
{
    /** ISO 3166-1 alpha-2 country code used before per-user/geo detection is available. */
    public string $default_country;

    public static function group(): string
    {
        return 'localization';
    }
}
