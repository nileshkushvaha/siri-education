<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Set only when the admin actually pastes a new Google service-
        // account JSON (never on an ordinary settings save) — lets the
        // "Test Google Configuration" diagnostics prove a credential
        // rotation (e.g. after a compromised key) actually took effect,
        // without ever exposing the credential itself.
        $this->migrator->add('meeting.google_credentials_updated_at', null);
    }
};
