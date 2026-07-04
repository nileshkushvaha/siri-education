<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('booking.max_daily_bookings_per_teacher', null);
        $this->migrator->add('booking.min_lead_hours', 2);
        $this->migrator->add('booking.max_advance_days', 90);
    }
};
