<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * GAP-008 (SRS §15.18) — Version 1 demo-to-paid conversion incentive
 * rules. A single global rule, not a multi-row campaign framework
 * (explicitly excluded by the phase prompt) — mirrors
 * ReferralCampaign's "empty list = applies to all" convention for the
 * two optional applicability lists, using simple ID allowlists rather
 * than a new pivot table since this is a singleton rule, not a
 * many-row campaign.
 */
class DemoConversionIncentiveSettings extends Settings
{
    /** Master switch — false stops the listener from ever creating an award. */
    public bool $enabled;

    /** Maximum days between the qualifying demo's completion and the converting paid lesson's completion. */
    public int $conversion_window_days;

    /** The converting paid lesson must be at least the Nth completed paid lesson for the student/instructor pair. */
    public int $min_completed_paid_lessons;

    /** Fixed bonus amount, integer minor units — never a percentage (SRS §15.18 recommended V1 approach). */
    public int $bonus_amount_minor;

    /** Version 1: primarily INR (SRS §15.16). */
    public string $bonus_currency_code;

    /** No further awards are created for a student/instructor pair once this many exist. */
    public int $max_awards_per_pair;

    /**
     * Empty = applies to every country. Restricts by the INSTRUCTOR's
     * profile country (CountryResolver::forInstructor()) — a financial
     * obligation resolved at the moment it's created, per that
     * resolver's own documented convention.
     *
     * @var list<int>
     */
    public array $applicable_country_ids;

    /**
     * Empty = applies to every subject. Matched against the converting
     * paid lesson's subject_id.
     *
     * @var list<int>
     */
    public array $applicable_subject_ids;

    public static function group(): string
    {
        return 'demo_conversion_incentive';
    }
}
