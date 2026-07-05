<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Moves the country/locale-switching fields out of GeneralSettings (where
 * they were temporarily merged) into their own LocalizationSettings group,
 * per the Phase 1 settings spec's 12 required groups. GeneralSettings keeps
 * timezone/language/date-time-format/currency — no fields are duplicated
 * across the two groups.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->rename('general.default_country', 'localization.default_country');
        $this->migrator->rename('general.country_detection_enabled', 'localization.country_detection_enabled');
        $this->migrator->rename('general.allow_user_locale_switching', 'localization.allow_user_locale_switching');
    }

    public function down(): void
    {
        $this->migrator->rename('localization.default_country', 'general.default_country');
        $this->migrator->rename('localization.country_detection_enabled', 'general.country_detection_enabled');
        $this->migrator->rename('localization.allow_user_locale_switching', 'general.allow_user_locale_switching');
    }
};
