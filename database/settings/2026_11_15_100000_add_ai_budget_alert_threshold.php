<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * AI-E0: the point at which spend raises an operational alert.
 *
 * Expressed as a fraction of the configured ceiling rather than an
 * absolute amount, so it keeps working when a limit is raised — an
 * absolute threshold silently stops warning the moment somebody
 * increases the budget past it, which is exactly when warnings matter.
 *
 * 0.8 gives a day's notice at typical burn rates before the budget
 * guard starts blocking runs. Set to null to disable alerting entirely.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('ai.budget_alert_threshold', 0.8);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('ai.budget_alert_threshold');
    }
};
