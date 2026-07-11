<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Phase 14.2 — instructor compensation is never calculated from
 * student-facing price. The global commission/fixed-rate settings are
 * removed deliberately (not silently): every historical earning carries
 * its own immutable calculation snapshot, so no historical read depends
 * on these keys. Compensation is now configured per instructor through
 * effective-dated agreements.
 *
 * earnings_enabled is forced (and now defaults) to OFF: it must be
 * enabled explicitly by an administrator only after valid compensation
 * agreements exist.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->update('instructor_earnings.earnings_enabled', fn (): bool => false);

        $this->migrator->delete('instructor_earnings.default_calculation_type');
        $this->migrator->delete('instructor_earnings.default_percentage');
        $this->migrator->delete('instructor_earnings.default_fixed_amount_minor');
        $this->migrator->delete('instructor_earnings.default_currency_code');

        // Demo lessons stay free to students; demo compensation is a
        // deliberate, explicitly configured policy — never a derivative
        // of student pricing. Default: no demo compensation.
        $this->migrator->add('instructor_earnings.demo_compensation_policy', 'none');
        $this->migrator->add('instructor_earnings.demo_fixed_amount_minor', null);
    }
};
