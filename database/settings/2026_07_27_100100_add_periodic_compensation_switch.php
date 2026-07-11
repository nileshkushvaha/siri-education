<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Phase 14.3 — periodic (daily/weekly/monthly) compensation stays
 * behind its own switch, OFF by default: those bases pay fixed
 * contractual amounts per period regardless of taught lessons, and
 * their attendance/leave/suspension/partial-period rules are not yet
 * formally defined. Hourly agreements are unaffected. While off,
 * periodic agreements cannot be activated and periodic accrual creates
 * nothing.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('instructor_earnings.periodic_compensation_enabled', false);
    }
};
