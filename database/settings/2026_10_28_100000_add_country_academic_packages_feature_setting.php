<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Phase 4D — country-aware academic PACKAGE rollout switch
 * (CountryFeature::CountryAcademicPackages). Same conservative default
 * as its Phase 3 demo sibling: off, so migrating this changes no
 * existing package or booking behavior until an admin deliberately
 * enables it for a configured country.
 *
 * Deliberately its own setting rather than a reuse of
 * features.country_academic_booking_enabled — see
 * CountryFeature::CountryAcademicPackages for why packages must not
 * hang off the demo-lessons dependency chain.
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
