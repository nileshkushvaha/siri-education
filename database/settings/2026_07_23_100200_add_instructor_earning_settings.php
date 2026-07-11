<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('instructor_earnings.earnings_enabled', true);
        // Percentage of the student paid amount is the default model;
        // fixed is the fallback for free/demo lessons (no student amount).
        $this->migrator->add('instructor_earnings.default_calculation_type', 'percentage');
        $this->migrator->add('instructor_earnings.default_percentage', 70.0);
        $this->migrator->add('instructor_earnings.default_fixed_amount_minor', null);
        $this->migrator->add('instructor_earnings.default_currency_code', null);
        // 7 days: the dispute window before money becomes releasable.
        $this->migrator->add('instructor_earnings.hold_days', 7);
        $this->migrator->add('instructor_earnings.auto_release_enabled', true);
        $this->migrator->add('instructor_earnings.minimum_settlement_amount_minor', null);
        $this->migrator->add('instructor_earnings.settlement_frequency', 'manual');
    }
};
