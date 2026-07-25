<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('messaging.post_lesson_window_days', 7);
        $this->migrator->add('messaging.attachments_enabled', true);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('messaging.post_lesson_window_days');
        $this->migrator->deleteIfExists('messaging.attachments_enabled');
    }
};
