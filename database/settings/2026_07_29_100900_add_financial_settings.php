<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Consolidated financial settings baseline (replaces the
 * earlier per-feature settings migrations). Instructor compensation is
 * agreement-based and independent of student pricing — no commission
 * key exists. All three feature switches default OFF and may only be
 * flipped through FinancialFeatureConfigurationService, which runs the
 * activation preflights.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        // ── Feature switches (all OFF; service-guarded) ──────────────
        $this->migrator->add('instructor_earnings.earnings_enabled', false);
        $this->migrator->add('instructor_earnings.periodic_compensation_enabled', false);
        $this->migrator->add('instructor_earnings.withdrawals_enabled', false);

        // ── Hold & release ───────────────────────────────────────────
        $this->migrator->add('instructor_earnings.hold_days', 7);
        $this->migrator->add('instructor_earnings.auto_release_enabled', true);

        // ── Settlement ───────────────────────────────────────────────
        $this->migrator->add('instructor_earnings.minimum_settlement_amount_minor', null);
        $this->migrator->add('instructor_earnings.settlement_frequency', 'manual');

        // ── Demo compensation (explicit policy, never price-derived) ─
        $this->migrator->add('instructor_earnings.demo_compensation_policy', 'none');
        $this->migrator->add('instructor_earnings.demo_fixed_amount_minor', null);

        // ── Compensation exception retries (backoff) ──────
        $this->migrator->add('instructor_earnings.compensation_retry_max_attempts', 10);

        // ── Withdrawals ──────────────────────────────────────────────
        $this->migrator->add('instructor_earnings.minimum_withdrawal_minor', 50000);
        $this->migrator->add('instructor_earnings.maximum_withdrawal_minor', null);
        $this->migrator->add('instructor_earnings.maximum_active_requests_per_instructor', 1);
        $this->migrator->add('instructor_earnings.payout_method_verification_required', true);
        $this->migrator->add('instructor_earnings.instructor_cancellation_enabled', true);
        $this->migrator->add('instructor_earnings.withdrawal_review_required', true);
    }
};
