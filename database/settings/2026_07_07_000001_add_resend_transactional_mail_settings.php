<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $defaultName = config('app.name', 'Sphere Education');
        $defaultEmail = 'noreply@example.com';

        $this->migrator->add('mail.transactional_domain', null);

        foreach (['auth', 'booking', 'payment', 'tutor', 'wallet', 'support', 'admin'] as $area) {
            $this->migrator->add("mail.{$area}_from_name", $defaultName);
            $this->migrator->add("mail.{$area}_from_email", $defaultEmail);
        }
    }
};
