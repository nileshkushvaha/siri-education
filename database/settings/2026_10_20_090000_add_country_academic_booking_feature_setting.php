<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Phase 3 — country-aware academic Demo booking rollout switch
 * (CountryFeature::CountryAcademicBooking). Conservative default: off,
 * so the dev DB's existing (unconfigured) Education Systems do not
 * change any live Demo booking behavior the moment this migrates.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('features.country_academic_booking_enabled', false);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('features.country_academic_booking_enabled');
    }
};
