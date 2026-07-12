<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Phase 16A.1 — rollout POLICY, not a kill switch.
 * `payout_execution_enabled` (Phase 16A) remains the sole authoritative
 * switch and stays false; this setting only narrows which routes would
 * even be considered once execution is eventually turned on. Defaults
 * to `india_inr_only` per the approved routing policy for the upcoming
 * RazorpayX (Phase 16B) integration.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('instructor_earnings.payout_rollout_scope', 'india_inr_only');
    }
};
