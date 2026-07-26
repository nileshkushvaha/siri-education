<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Payout execution settings. `payout_execution_enabled` joins the
 * three existing service-guarded switches (all four may only
 * be flipped through FinancialFeatureConfigurationService). The other
 * keys here are plain settings (provider selection, maker-checker,
 * retry/reconciliation policy) — not kill switches, so they save
 * normally through InstructorEarningSettings::save().
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('instructor_earnings.payout_execution_enabled', false);
        $this->migrator->add('instructor_earnings.payout_provider', 'fake');
        $this->migrator->add('instructor_earnings.payout_maker_checker_enabled', true);
        $this->migrator->add('instructor_earnings.payout_auto_retry_enabled', false);
        $this->migrator->add('instructor_earnings.payout_reconciliation_enabled', true);
        $this->migrator->add('instructor_earnings.payout_max_attempts', 5);
        $this->migrator->add('instructor_earnings.payout_unknown_timeout_minutes', 30);
        // Lets the fake provider simulate a non-local staging environment
        // without ever becoming a real provider — off by default; local/
        // testing environments never need this flag to use the fake provider.
        $this->migrator->add('instructor_earnings.payout_fake_provider_staging_enabled', false);
    }
};
