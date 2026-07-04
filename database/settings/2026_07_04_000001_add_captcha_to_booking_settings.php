<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('booking.captcha_enabled', false);
        $this->migrator->add('booking.turnstile_site_key', null);
        $this->migrator->add('booking.turnstile_secret_key', null);
    }
};
