<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Clears the seeded `mail.driver` value on installs that predate the
     * setting being wired up.
     *
     * Until TransactionalMailSender::mailer() started reading it, nothing in
     * the app consumed `mail.driver` — production was hard-coded to 'resend'.
     * Every stored value is therefore vestigial: it was seeded as 'smtp' by
     * 2026_06_26_233120_fill_mail_settings.php and never represented a working,
     * chosen configuration. Honouring it as-is would switch existing installs
     * off their real transport the moment this deploys.
     *
     * Blank means "inherit MAIL_MAILER", so this restores exactly the
     * behaviour those installs already have. An administrator can then pick a
     * driver in Admin → Settings → Mail and it will genuinely take effect.
     */
    public function up(): void
    {
        $this->migrator->update('mail.driver', fn(): string => '');
    }
};
