<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // SRS §25.12 example format: SUP-2026-000123.
        $this->migrator->add('support_case.number_prefix', 'SUP');
        $this->migrator->add('support_case.number_format', '{prefix}-{year}-{sequence}');
        $this->migrator->add('support_case.sequence_digits', 6);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('support_case.number_prefix');
        $this->migrator->deleteIfExists('support_case.number_format');
        $this->migrator->deleteIfExists('support_case.sequence_digits');
    }
};
