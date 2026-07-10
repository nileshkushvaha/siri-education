<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Both default on: harmless while active_provider stays 'manual'
        // (the platform's off switch — see MeetingProviderResolver), and
        // an admin who deliberately switches to a real/fake provider gets
        // the natural "just works" behavior for both booking kinds
        // without a second opt-in step.
        $this->migrator->add('meeting.create_for_demo_bookings', true);
        $this->migrator->add('meeting.create_for_paid_bookings', true);
    }
};
