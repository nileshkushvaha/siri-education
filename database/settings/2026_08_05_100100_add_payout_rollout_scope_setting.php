<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Rollout POLICY, not a kill switch. `payout_execution_enabled`
 * remains the sole authoritative switch; this setting only narrows
 * which routes would even be considered once execution is turned on.
 * Defaults to `india_inr_only` per the approved routing policy for the
 * RazorpayX integration.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('instructor_earnings.payout_rollout_scope', 'india_inr_only');
    }
};
