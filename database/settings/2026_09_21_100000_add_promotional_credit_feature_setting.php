<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Conservative default: an admin must consciously enable
        // promotional credits before any campaign or manual issuance
        // can take effect.
        $this->migrator->add('features.promotional_credit_enabled', false);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('features.promotional_credit_enabled');
    }
};
