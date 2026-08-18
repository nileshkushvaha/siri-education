<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Country\Enums\CountryFeature;
use App\Country\Services\CountryFeatureResolver;
use App\Models\Country;
use App\Settings\MeetingSettings;

/**
 * The single "is recording currently available" answer (SRS §20.8,
 * extended for country-level availability). `CountryFeatureResolver`
 * folds in the platform-wide
 * `FeatureSettings::recording_enabled` outer switch (always evaluated
 * first, including any country-level override) — the meeting-level
 * `MeetingSettings::recording_enabled` default can never turn recording
 * on by itself, and no country override can bypass an outer OFF
 * either. `$country` is optional so the one existing caller (the
 * platform settings page, which shows the global-only effective
 * status) is unaffected; a caller with a specific country in scope
 * should pass it.
 *
 * No meeting provider currently declares a recording capability of its
 * own — this resolver's result is the complete effective rule as of
 * today, with no further AND-term to apply. The ingestion, storage,
 * retention and delivery pipeline behind it IS built (see
 * RecordingIngestionService and the RecordingStorage abstraction);
 * what is still missing is a provider integration that can hand SIRI
 * an actual recording file, which is a provider question rather than a
 * storage one.
 */
final class RecordingAvailabilityResolver
{
    public function __construct(
        private readonly CountryFeatureResolver $countryFeatures,
        private readonly MeetingSettings $meetings,
    ) {}

    public function isAvailable(?Country $country = null): bool
    {
        if (! $this->countryFeatures->isEnabled(CountryFeature::RecordingAvailability, $country)) {
            return false;
        }

        return $this->meetings->recording_enabled;
    }
}
