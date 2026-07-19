<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Conservative V1 default per SRS §7.11 ("Administrators
        // configure reminder behavior globally") — one reminder 24
        // hours before due time; enabled by default since GAP-020 is a
        // Critical-severity missing feature, not an opt-in beta.
        $this->migrator->add('homework.homework_due_reminders_enabled', true);
        $this->migrator->add('homework.homework_due_reminder_offset_hours', [24]);
        $this->migrator->add('homework.homework_reminder_channel_email_enabled', true);
        $this->migrator->add('homework.homework_reminder_channel_whatsapp_enabled', false);
        $this->migrator->add('homework.homework_reminder_channel_sms_enabled', false);
    }
};
