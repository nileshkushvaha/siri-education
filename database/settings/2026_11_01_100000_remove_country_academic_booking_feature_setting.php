<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->deleteIfExists('features.country_academic_booking_enabled');
    }

    public function down(): void
    {
        $this->migrator->add('features.country_academic_booking_enabled', false);
    }
};
