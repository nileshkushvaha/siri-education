<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('booking.channel_email_enabled', true);
        $this->migrator->add('booking.channel_whatsapp_enabled', false); // future
        $this->migrator->add('booking.channel_sms_enabled', false);      // future
    }
};
