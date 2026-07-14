<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('mail.review_from_name', config('app.name', 'Sphere Education'));
        $this->migrator->add('mail.review_from_email', 'noreply@example.com');
    }
};
