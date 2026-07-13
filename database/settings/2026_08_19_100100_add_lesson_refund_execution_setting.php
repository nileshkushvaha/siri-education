<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Disabled-safe: wallet refund execution ships OFF — approved
        // dispositions wait in Ready until an operator enables the switch.
        $this->migrator->add('instructor_earnings.lesson_refund_execution_enabled', false);
    }
};
