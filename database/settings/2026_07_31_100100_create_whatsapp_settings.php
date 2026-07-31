<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('whatsapp.enabled', false);
        $this->migrator->add('whatsapp.number', '');
        $this->migrator->add('whatsapp.default_message', 'Hello SIRI Education, I would like to know more about your courses.');
        $this->migrator->add('whatsapp.desktop_visible', true);
        $this->migrator->add('whatsapp.mobile_visible', true);
        $this->migrator->add('whatsapp.allowed_pages', []);
        $this->migrator->add('whatsapp.excluded_pages', []);
    }
};
