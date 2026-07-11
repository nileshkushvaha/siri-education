<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class InstructorEarningSettings extends Settings
{
    /**
     * Platform-wide switch — false stops all automatic earning creation
     * (lesson-triggered and periodic accrual alike). Defaults to false;
     * enable only after valid compensation agreements exist.
     */
    public bool $earnings_enabled;

    /**
     * Phase 14.2: compensation is configured per instructor through
     * effective-dated agreements and is NEVER calculated from the
     * student-facing price. The former global commission settings
     * (default_calculation_type / default_percentage /
     * default_fixed_amount_minor / default_currency_code) were removed
     * deliberately — every historical earning carries its own snapshot.
     */

    /**
     * Separate rollout gate for daily/weekly/monthly (fixed contractual)
     * compensation. OFF until attendance, workload, leave, suspension,
     * and partial-period rules are formally defined: while off, periodic
     * agreements cannot be activated and periodic accrual creates
     * nothing. Hourly agreements are unaffected.
     */
    public bool $periodic_compensation_enabled;

    /** 'none' | 'fixed_demo_amount' — demo lessons stay free to students; compensation is explicit. */
    public string $demo_compensation_policy;

    /** Explicit demo compensation in minor units; required when the policy is fixed_demo_amount. */
    public ?int $demo_fixed_amount_minor;

    /** Days after lesson completion before an earning may be released. */
    public int $hold_days;

    /** Gates the instructor-earnings:release sweep. */
    public bool $auto_release_enabled;

    /** Minimum batch total; null = no minimum. */
    public ?int $minimum_settlement_amount_minor;

    /** 'manual' | 'weekly' | 'monthly' — informational this phase; batches are always admin-created. */
    public string $settlement_frequency;

    /** Platform-wide switch for the instructor withdrawal flow (Phase 15). */
    public bool $withdrawals_enabled;

    /** Smallest allowed withdrawal request, integer minor units. */
    public int $minimum_withdrawal_minor;

    /** Largest allowed withdrawal request, integer minor units; null = no cap. */
    public ?int $maximum_withdrawal_minor;

    /** Open (submitted/under-review/approved) requests an instructor may hold at once. */
    public int $maximum_active_requests_per_instructor;

    /** Withdrawals may only use verified payout methods (keep true in production). */
    public bool $payout_method_verification_required;

    /** Instructors may cancel their own submitted/under-review requests. */
    public bool $instructor_cancellation_enabled;

    /** True = a request must enter under_review before it can be approved. */
    public bool $withdrawal_review_required;

    public static function group(): string
    {
        return 'instructor_earnings';
    }
}
