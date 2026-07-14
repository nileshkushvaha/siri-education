<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('reviews.review_channel_email_enabled', true);
        $this->migrator->add('reviews.review_channel_whatsapp_enabled', false);
        $this->migrator->add('reviews.review_channel_sms_enabled', false);
    }
};
