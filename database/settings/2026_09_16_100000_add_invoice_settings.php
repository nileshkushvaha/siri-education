<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // SRS §14.22 example format: STEM/INV/2026/000001.
        $this->migrator->add('invoice.number_prefix', 'STEM/INV');
        $this->migrator->add('invoice.number_format', '{prefix}/{year}/{sequence}');
        $this->migrator->add('invoice.sequence_digits', 6);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('invoice.number_prefix');
        $this->migrator->deleteIfExists('invoice.number_format');
        $this->migrator->deleteIfExists('invoice.sequence_digits');
    }
};
