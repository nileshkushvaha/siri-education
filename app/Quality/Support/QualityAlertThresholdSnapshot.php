<?php

declare(strict_types=1);

namespace App\Quality\Support;

use App\Settings\ReviewSettings;

/**
 * Captures the exact threshold values in force at detection time onto
 * the alert row itself — a later settings change (e.g.
 * `low_rating_threshold` 2 → 3) never retroactively reinterprets an
 * already-created alert, mirroring every other settings-snapshot
 * convention in the Reviews domain (`lesson_reviews.settings_snapshot`,
 * `lesson_review_eligibilities.metadata`).
 */
final class QualityAlertThresholdSnapshot
{
    /** @return array<string, int|bool> */
    public static function capture(ReviewSettings $settings): array
    {
        return [
            'low_rating_threshold' => $settings->low_rating_threshold,
            'single_low_rating_alert_enabled' => $settings->single_low_rating_alert_enabled,
            'repeated_low_rating_count' => $settings->repeated_low_rating_count,
            'repeated_low_rating_window_days' => $settings->repeated_low_rating_window_days,
            'repeated_no_show_count' => $settings->repeated_no_show_count,
            'repeated_no_show_window_days' => $settings->repeated_no_show_window_days,
            'repeated_cancellation_count' => $settings->repeated_cancellation_count,
            'repeated_cancellation_window_days' => $settings->repeated_cancellation_window_days,
        ];
    }
}
