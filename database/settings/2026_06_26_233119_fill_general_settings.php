<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.app_name', config('app.name', 'Sphere Education'));
        $this->migrator->add('general.app_short_name', 'Sphere');
        $this->migrator->add('general.organization_name', null);
        $this->migrator->add('general.support_email', 'support@sirieducation.com');
        $this->migrator->add('general.support_phone', null);
        $this->migrator->add('general.website_url', null);
        $this->migrator->add('general.address', null);

        $this->migrator->add('general.logo', null);
        $this->migrator->add('general.logo_dark', null);
        $this->migrator->add('general.favicon', null);

        $this->migrator->add('general.default_timezone', 'Asia/Kolkata');
        $this->migrator->add('general.default_language', 'en');
        $this->migrator->add('general.date_format', 'Y-m-d');
        $this->migrator->add('general.time_format', 'H:i');

        $this->migrator->add('general.default_currency', 'INR');
        $this->migrator->add('general.decimal_precision', 2);
        $this->migrator->add('general.maintenance_mode', false);

        $this->migrator->add('general.footer_copyright', '© '.date('Y').' Sphere Education. All rights reserved.');
        $this->migrator->add('general.footer_text', null);
    }
};
