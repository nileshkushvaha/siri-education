<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('mail.review_from_name', config('app.name'));

        // A real sendable address, not a placeholder — see the note in
        // 2026_07_07_000001_add_resend_transactional_mail_settings.php.
        $this->migrator->add('mail.review_from_email', 'no-reply@sirieducation.com');
    }
};
