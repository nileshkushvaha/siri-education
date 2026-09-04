<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * A separate, shorter lead time for free demo bookings. Paid lessons
 * keep booking.minimum_booking_notice_minutes (120 by default); a demo
 * may start as soon as 30 minutes from now, so a student who is ready
 * can try an instructor who is free right away.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('booking.demo_minimum_booking_notice_minutes', 30);
    }

    public function down(): void
    {
        $this->migrator->delete('booking.demo_minimum_booking_notice_minutes');
    }
};
