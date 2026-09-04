<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Retires the last two platform-wide wallet settings.
 *
 * `wallet.low_balance_threshold` was a single currency-blind number
 * compared against every wallet; it now lives per currency as
 * `currencies.low_balance_threshold_minor` (see the
 * add_low_balance_and_recharge_multiple_to_currencies_table migration)
 * and is edited on Settings → Wallet.
 *
 * `wallet.recurring_deduction_hours_before_lesson` had no consumer at
 * all — recurring wallet deduction was never built — and only confused
 * admins. Removed rather than kept "for later".
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->delete('wallet.low_balance_threshold');
        $this->migrator->delete('wallet.recurring_deduction_hours_before_lesson');
    }

    public function down(): void
    {
        $this->migrator->add('wallet.low_balance_threshold', 500.0);
        $this->migrator->add('wallet.recurring_deduction_hours_before_lesson', 24);
    }
};
