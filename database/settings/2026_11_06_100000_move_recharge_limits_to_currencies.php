<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Retires `wallet.minimum_recharge_amount` / `wallet.maximum_recharge_amount`.
 *
 * These were platform-wide floats that WalletRechargeService re-expressed
 * in each wallet's own minor units, so one configured "100" meant ₹100 in
 * India and $100 in the United States — nine different amounts of money
 * from a single scalar, in an application that deliberately has no
 * exchange rate anywhere (SRS §13.7). The limit now lives on
 * `currencies.minimum_recharge_minor` / `maximum_recharge_minor`, in
 * integer minor units alongside the currency that denominates it. See the
 * 2026_11_02_100000_add_recharge_limits_to_currencies_table migration.
 *
 * Deleted rather than aliased: keeping a platform-wide fallback would
 * preserve exactly the currency-blind behaviour that made the old shape
 * unsound, and the admin form no longer offers the fields. The
 * SRS §13.12 minimums (INR/USD/GBP) are seeded onto `currencies` by that
 * schema migration; no configured maximum is carried over, because the
 * old value was never meaningful in more than one currency to begin with.
 *
 * `wallet.low_balance_threshold` and
 * `wallet.recurring_deduction_hours_before_lesson` are deliberately left
 * in place — the threshold has the same currency-blind shape but a real
 * current consumer (WalletFinancialReportRepository) and no recharge
 * dependency, so it is tracked separately rather than changed in a
 * recharge migration.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->delete('wallet.minimum_recharge_amount');
        $this->migrator->delete('wallet.maximum_recharge_amount');
    }

    public function down(): void
    {
        $this->migrator->add('wallet.minimum_recharge_amount', 100.0);
        $this->migrator->add('wallet.maximum_recharge_amount', 50000.0);
    }
};
