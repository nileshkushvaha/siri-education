<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Removes duplication introduced by 2026_07_05_140000_add_platform_foundation_settings:
 *
 *  - localization.* duplicated general.default_timezone/default_language —
 *    the genuinely new fields (default_country, country_detection_enabled,
 *    allow_user_locale_switching) move to the `general` group instead.
 *  - booking.minimum_booking_notice_minutes / maximum_advance_booking_days
 *    duplicated booking.min_lead_hours / max_advance_days (the fields
 *    BookingWindowRule actually reads) under different names/units — the
 *    duplicates were dead, so they're removed rather than kept in sync.
 *  - features.wallet_enabled / referral_enabled / recording_enabled
 *    duplicated WalletSettings::enabled / ReferralSettings::enabled /
 *    MeetingSettings::recording_enabled — one on/off switch per feature.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.default_country', 'IN');
        $this->migrator->add('general.country_detection_enabled', false);
        $this->migrator->add('general.allow_user_locale_switching', false);

        $this->migrator->deleteIfExists('localization.default_country');
        $this->migrator->deleteIfExists('localization.fallback_currency');
        $this->migrator->deleteIfExists('localization.fallback_language');
        $this->migrator->deleteIfExists('localization.fallback_timezone');
        $this->migrator->deleteIfExists('localization.country_detection_enabled');
        $this->migrator->deleteIfExists('localization.allow_user_locale_switching');

        $this->migrator->deleteIfExists('booking.minimum_booking_notice_minutes');
        $this->migrator->deleteIfExists('booking.maximum_advance_booking_days');

        $this->migrator->deleteIfExists('features.wallet_enabled');
        $this->migrator->deleteIfExists('features.referral_enabled');
        $this->migrator->deleteIfExists('features.recording_enabled');
    }

    public function down(): void
    {
        $this->migrator->add('localization.default_country', 'IN');
        $this->migrator->add('localization.fallback_currency', 'INR');
        $this->migrator->add('localization.fallback_language', 'en');
        $this->migrator->add('localization.fallback_timezone', 'Asia/Kolkata');
        $this->migrator->add('localization.country_detection_enabled', false);
        $this->migrator->add('localization.allow_user_locale_switching', false);

        $this->migrator->add('booking.minimum_booking_notice_minutes', 120);
        $this->migrator->add('booking.maximum_advance_booking_days', 90);

        $this->migrator->add('features.wallet_enabled', false);
        $this->migrator->add('features.referral_enabled', false);
        $this->migrator->add('features.recording_enabled', false);

        $this->migrator->deleteIfExists('general.default_country');
        $this->migrator->deleteIfExists('general.country_detection_enabled');
        $this->migrator->deleteIfExists('general.allow_user_locale_switching');
    }
};
