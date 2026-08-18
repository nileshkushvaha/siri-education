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
 * was a decoy (never read by anything,
 * only ever edited by an admin form that had no runtime effect) and was
 * removed — see
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

    /** SRS §16.35 "Promotional credit permissions" — the global on/off switch (§20.17 "Promotional credit enabled/disabled"). */
    public bool $promotional_credit_enabled;

    /**
     * Phase 4D — master switch for the country-aware academic flow on
     * PERSONALIZED PACKAGES (structured instructor proposals and
     * package-funded paid booking), CountryFeature::CountryAcademicPackages.
     *
     * Separate from DemoLessons on purpose: turning free demos off must
     * not make every paid package unbookable. Off by default because
     * enabling assumes Education Systems, Levels, Curricula,
     * Published CurriculumVersions and instructor eligibilities are
     * already configured for the countries concerned.
     */
    public bool $country_academic_packages_enabled;

    /**
     * Phase P0 — master switch for the AI platform layer
     * (app/Ai). Off means no provider call can ever be made,
     * whatever the per-capability flags in AiSettings say: AiFeatureGate
     * checks this first. The capability flags themselves live in
     * AiSettings because they narrow this switch rather than compete
     * with it — same layering as recording_enabled above.
     */
    public bool $ai_enabled;

    public static function group(): string
    {
        return 'features';
    }
}
