<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Disabled-safe: earning reconciliation execution ships OFF —
        // approved dispositions wait in Ready until an operator enables it.
        $this->migrator->add('instructor_earnings.earning_reconciliation_execution_enabled', false);
    }
};
