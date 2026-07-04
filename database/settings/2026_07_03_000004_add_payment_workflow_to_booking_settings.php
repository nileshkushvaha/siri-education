<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('booking.payment_provider', 'fake');
        $this->migrator->add('booking.payment_reservation_minutes', 30);
    }
};
