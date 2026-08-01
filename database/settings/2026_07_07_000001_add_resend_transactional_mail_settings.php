<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $defaultName = config('app.name');

        // These per-area settings outrank both mail.from_email and
        // MAIL_FROM_ADDRESS, so the default has to be a real sendable address on
        // the verified Resend domain — never an sirieducation.com placeholder.
        $defaultEmail = 'no-reply@sirieducation.com';

        foreach (['auth', 'booking', 'payment', 'tutor', 'wallet', 'support', 'admin'] as $area) {
            $this->migrator->add("mail.{$area}_from_name", $defaultName);
            $this->migrator->add("mail.{$area}_from_email", $defaultEmail);
        }
    }
};
