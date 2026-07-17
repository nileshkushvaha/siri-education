<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * The single on/off switch per feature module. Domain settings classes
 * (WalletSettings, MeetingSettings, BookingSettings, ...) hold pure
 * configuration for that module and never redeclare their own `enabled`
 * field — this class is the one place that decides whether a module is
 * active at all, so there is exactly one switch per feature to check or
 * toggle, never two.
 *
 * `recording_enabled` here is deliberately distinct from
 * MeetingSettings::$recording_enabled: this flag gates whether the
 * Recording capability exists on the platform at all, while
 * MeetingSettings::$recording_enabled is the default recording behavior
 * for a session once the capability is on. Same name, different layer —
 * not a duplicate.
 *
 * Reviews are the one exception to "this class owns every module
 * switch": ReviewSettings::$reviews_enabled (group `reviews`) is the
 * sole canonical Reviews on/off switch — it existed first, is the only
 * one any review-domain code reads, and carries review-specific
 * disabled-safe defaults. A same-named `reviews_enabled` property here
 * was a Phase 17T-flagged decoy (Finding S-1: never read by anything,
 * only ever edited by an admin form that had no runtime effect) and was
 * removed in Phase 17U.2 — see
 * database/settings/2026_09_05_100100_remove_decoy_features_reviews_enabled_setting.php.
 * Do not re-add a `reviews_enabled` property here.
 */
class FeatureSettings extends Settings
{
    public bool $demo_lessons_enabled;

    public bool $wallet_enabled;

    public bool $referral_enabled;

    public bool $waitlist_enabled;

    public bool $homework_enabled;

    public bool $recording_enabled;

    public static function group(): string
    {
        return 'features';
    }
}
