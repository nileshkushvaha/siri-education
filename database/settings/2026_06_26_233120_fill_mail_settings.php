<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('mail.from_name', config('app.name'));

        // The settings table is the authority for the sender address —
        // TransactionalMailSender reads it before ever consulting
        // MAIL_FROM_ADDRESS — so this default must be a real, sendable address on
        // the verified Resend domain, never an sirieducation.com placeholder.
        $this->migrator->add('mail.from_email', 'no-reply@sirieducation.com');

        // Blank means "inherit MAIL_MAILER" — TransactionalMailSender::mailer()
        // honours this setting whenever it names a configured mailer, so seeding
        // a concrete driver here would silently override the environment on a
        // fresh install (and on every test database) before an administrator has
        // chosen one.
        $this->migrator->add('mail.driver', '');
        $this->migrator->add('mail.host', 'smtp.mailtrap.io');
        $this->migrator->add('mail.port', 587);
        $this->migrator->add('mail.username', null);
        $this->migrator->add('mail.password', null);
        $this->migrator->add('mail.encryption', 'tls');
        $this->migrator->add('mail.connection_timeout', 30);
    }
};
