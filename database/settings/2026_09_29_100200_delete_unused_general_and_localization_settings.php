<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Deletes eight settings that were editable in the admin panel but read by
     * no application code.
     *
     * Each was verified by searching for property access (`->field`) across
     * app/ and resources/ — the only references were the settings class, the
     * settings page that renders the control, and the migrations that seeded
     * it. Nothing consumed any of them, so removing them changes no behaviour.
     *
     * general.maintenance_mode
     *     The most misleading of the set: an administrator could switch
     *     "Maintenance Mode" on, see it save, and the public site stayed fully
     *     available. Laravel's own `php artisan down` is the real mechanism.
     *
     * general.default_language
     *     Locale comes from the app config; nothing read this.
     *
     * general.date_format / general.time_format
     *     Duplicates of live per-country columns (Country::$date_format /
     *     $time_format) and the per-user profile preference, both of which are
     *     what actually format dates. The global pair was never consulted.
     *
     * general.decimal_precision
     *     A dead duplicate of the live PaymentConfigurationSettings copy that
     *     the payment pages read. Two controls labelled "Decimal Precision"
     *     where only one had any effect.
     *
     * general.logo_dark
     *     Uploaded and stored, never rendered — no layout referenced it.
     *
     * localization.country_detection_enabled
     * localization.allow_user_locale_switching
     *     Neither toggle was consulted by any resolver or middleware.
     *     localization.default_country IS live (MarketplaceCountryResolver
     *     reads it) and is deliberately kept.
     *
     * Re-adding any of these should mean wiring it up in the same change.
     */
    public function up(): void
    {
        foreach ([
            'general.maintenance_mode',
            'general.default_language',
            'general.date_format',
            'general.time_format',
            'general.decimal_precision',
            'general.logo_dark',
            'localization.country_detection_enabled',
            'localization.allow_user_locale_switching',
        ] as $property) {
            $this->migrator->deleteIfExists($property);
        }
    }

    /**
     * Reversible on purpose. Without this, rolling back left
     * `localization.country_detection_enabled` and
     * `allow_user_locale_switching` deleted, and the older
     * `move_country_fields_to_localization_settings` migration's own
     * `down()` then tried to RENAME properties that no longer existed —
     * so `migrate:reset` / `migrate:refresh` failed outright.
     *
     * Values are the originals from `fill_general_settings` /
     * `add_platform_foundation_settings`. Restoring a row does not
     * restore any behaviour: nothing read these before and nothing reads
     * them now — see the note above about wiring one up before re-adding
     * it for real.
     */
    public function down(): void
    {
        foreach ([
            'general.maintenance_mode' => false,
            'general.default_language' => 'en',
            'general.date_format' => 'Y-m-d',
            'general.time_format' => 'H:i',
            'general.decimal_precision' => 2,
            'general.logo_dark' => null,
            'localization.country_detection_enabled' => false,
            'localization.allow_user_locale_switching' => false,
        ] as $property => $value) {
            if (! $this->migrator->exists($property)) {
                $this->migrator->add($property, $value);
            }
        }
    }
};
