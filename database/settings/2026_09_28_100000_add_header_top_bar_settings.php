<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.header_top_bar_enabled', false);
        $this->migrator->add('general.facebook_url', null);
        $this->migrator->add('general.instagram_url', null);
        $this->migrator->add('general.x_url', null);
        $this->migrator->add('general.youtube_url', null);
    }
};
