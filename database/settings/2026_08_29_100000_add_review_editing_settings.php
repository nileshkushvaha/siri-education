<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('reviews.review_editing_enabled', true);
        $this->migrator->add('reviews.review_edit_window_hours', 24);
    }
};
