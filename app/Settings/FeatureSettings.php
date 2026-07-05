<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * The single on/off switch per feature module. Domain settings classes
 * (WalletSettings, ReferralSettings, MeetingSettings, ...) hold pure
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
 */
class FeatureSettings extends Settings
{
    public bool $demo_lessons_enabled;

    public bool $wallet_enabled;

    public bool $referral_enabled;

    public bool $waitlist_enabled;

    public bool $homework_enabled;

    public bool $reviews_enabled;

    public bool $recording_enabled;

    public static function group(): string
    {
        return 'features';
    }
}
