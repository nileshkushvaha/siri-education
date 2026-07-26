<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * GAP-008 — disabled by default (SRS's own recommended V1 approach:
 * "no direct demo compensation initially, or demo-to-paid conversion
 * incentive only" — this is a new, real financial cost, never
 * silently turned on). Explicit down() (deleteIfExists) — a bare
 * add()-only migration would fail "already exists" on a later
 * rollback + re-migrate cycle, since settings ROWS survive a
 * migration-table rollback without one.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('demo_conversion_incentive.enabled', false);
        $this->migrator->add('demo_conversion_incentive.conversion_window_days', 7);
        $this->migrator->add('demo_conversion_incentive.min_completed_paid_lessons', 1);
        $this->migrator->add('demo_conversion_incentive.bonus_amount_minor', 20000);
        $this->migrator->add('demo_conversion_incentive.bonus_currency_code', 'INR');
        $this->migrator->add('demo_conversion_incentive.max_awards_per_pair', 1);
        $this->migrator->add('demo_conversion_incentive.applicable_country_ids', []);
        $this->migrator->add('demo_conversion_incentive.applicable_subject_ids', []);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('demo_conversion_incentive.enabled');
        $this->migrator->deleteIfExists('demo_conversion_incentive.conversion_window_days');
        $this->migrator->deleteIfExists('demo_conversion_incentive.min_completed_paid_lessons');
        $this->migrator->deleteIfExists('demo_conversion_incentive.bonus_amount_minor');
        $this->migrator->deleteIfExists('demo_conversion_incentive.bonus_currency_code');
        $this->migrator->deleteIfExists('demo_conversion_incentive.max_awards_per_pair');
        $this->migrator->deleteIfExists('demo_conversion_incentive.applicable_country_ids');
        $this->migrator->deleteIfExists('demo_conversion_incentive.applicable_subject_ids');
    }
};
