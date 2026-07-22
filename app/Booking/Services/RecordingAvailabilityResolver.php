<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Settings\FeatureSettings;
use App\Settings\MeetingSettings;

/**
 * The single "is recording currently available" answer (SRS §20.8,
 * GAP-027). FeatureSettings::recording_enabled is the platform-wide
 * outer switch and is always evaluated first — the meeting-level
 * MeetingSettings::recording_enabled default can never turn recording
 * on by itself, and no other current rule (provider capability or
 * otherwise) can override an outer OFF.
 *
 * No meeting provider currently declares a recording capability of its
 * own — this resolver's result is the complete effective rule as of
 * today, with no further AND-term to apply. ZoomMeetingProvider, the
 * only provider that references recording at all, deliberately never
 * consults this resolver: it hardcodes recording off unconditionally,
 * since no capture/storage/retention pipeline exists to safely receive
 * a real recording yet.
 */
final class RecordingAvailabilityResolver
{
    public function __construct(
        private readonly FeatureSettings $features,
        private readonly MeetingSettings $meetings,
    ) {}

    public function isAvailable(): bool
    {
        if (! $this->features->recording_enabled) {
            return false;
        }

        return $this->meetings->recording_enabled;
    }
}
