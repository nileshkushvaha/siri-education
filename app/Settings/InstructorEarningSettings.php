<?php

declare(strict_types=1);

namespace App\Settings;

use App\Earnings\Exceptions\CompensationException;
use App\Earnings\Support\FinancialFeatureToggle;
use Illuminate\Support\Facades\DB;
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

    /** Automatic retry attempts per blocked lesson before the exception is marked exhausted. */
    public int $compensation_retry_max_attempts;

    /**
     * Phase 16A — platform-wide payout execution switch. False stops
     * InstructorPayoutExecutionService::queueExecution() and the
     * execution job from ever calling a provider.
     */
    public bool $payout_execution_enabled;

    /** Registered InstructorPayoutProviderInterface key; 'fake' is the only adapter Phase 16A ships. */
    public string $payout_provider;

    /** True = the withdrawal approver and the payout executor must be different users. */
    public bool $payout_maker_checker_enabled;

    /** Gates the bounded automatic retry policy (§25); manual, permission-gated retry is unaffected. */
    public bool $payout_auto_retry_enabled;

    /** Gates the instructor-payouts:reconcile sweep. */
    public bool $payout_reconciliation_enabled;

    /** Bounded automatic + manual retry ceiling per logical execution. */
    public int $payout_max_attempts;

    /** How long an attempt may sit unsynced in processing/unknown before reconciliation treats it as due. */
    public int $payout_unknown_timeout_minutes;

    /** Lets the fake provider be selected in a non-local/testing (e.g. staging) environment. Never makes it a real provider. */
    public bool $payout_fake_provider_staging_enabled;

    /** Phase 16A.1 — rollout policy (not a kill switch); see App\Earnings\Enums\PayoutRolloutScope. */
    public string $payout_rollout_scope;

    /**
     * Phase 14.5 write guard, extended in Phase 16A: the four financial
     * feature switches may only change through
     * FinancialFeatureConfigurationService (which runs the activation
     * preflights). Every other save() that flips one of them —
     * Filament, commands, seeders, controllers, direct calls — throws.
     * Non-switch settings save normally.
     */
    public function save(): static
    {
        if (! FinancialFeatureToggle::isUnguarded()) {
            $persisted = DB::table('settings')
                ->where('group', static::group())
                ->whereIn('name', ['earnings_enabled', 'withdrawals_enabled', 'periodic_compensation_enabled', 'payout_execution_enabled'])
                ->pluck('payload', 'name');

            foreach (['earnings_enabled', 'withdrawals_enabled', 'periodic_compensation_enabled', 'payout_execution_enabled'] as $switch) {
                if ($persisted->has($switch) && json_decode((string) $persisted[$switch]) !== $this->{$switch}) {
                    throw new CompensationException(sprintf(
                        'The %s switch can only be changed through FinancialFeatureConfigurationService, which enforces the activation preflight.',
                        $switch,
                    ));
                }
            }
        }

        return parent::save();
    }

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
