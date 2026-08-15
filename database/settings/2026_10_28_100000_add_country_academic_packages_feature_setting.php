<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Phase 4D — country-aware academic PACKAGE rollout switch
 * (CountryFeature::CountryAcademicPackages). Same conservative default
 * Off by default, so migrating this changes no
 * existing package or booking behavior until an admin deliberately
 * enables it for a configured country.
 *
 * Deliberately independent from Demo Lessons — see
 * CountryFeature::CountryAcademicPackages for why paid packages must
 * not hang off the demo-lessons dependency chain.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('features.country_academic_packages_enabled', false);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('features.country_academic_packages_enabled');
    }
};
