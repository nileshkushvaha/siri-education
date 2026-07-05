<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Renames the two BookingSettings fields BookingWindowRule reads to match
 * the Phase 1 settings spec's naming — no duplicate field is introduced,
 * this is a straight rename (+ unit conversion for the lead-time field).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->rename('booking.min_lead_hours', 'booking.minimum_booking_notice_minutes');
        $this->migrator->update('booking.minimum_booking_notice_minutes', fn (int $hours): int => $hours * 60);

        $this->migrator->rename('booking.max_advance_days', 'booking.maximum_advance_booking_days');
    }

    public function down(): void
    {
        $this->migrator->update('booking.minimum_booking_notice_minutes', fn (int $minutes): int => intdiv($minutes, 60));
        $this->migrator->rename('booking.minimum_booking_notice_minutes', 'booking.min_lead_hours');

        $this->migrator->rename('booking.maximum_advance_booking_days', 'booking.max_advance_days');
    }
};
