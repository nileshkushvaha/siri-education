<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Detection-only reconciliation settings, mirroring
 * instructor_earnings.payout_reconciliation_enabled /
 * payout_unknown_timeout_minutes (see 2026_08_01_100500_add_payout_execution_settings.php).
 * Enabled by default is safe: reconcileDue() only ever polls status and
 * raises issues/applies the same idempotent finalization webhooks
 * already use — it never moves money on its own.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('payment_gateways.booking_payment_reconciliation_enabled', true);
        $this->migrator->add('payment_gateways.booking_payment_unknown_timeout_minutes', 30);
    }
};
