<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Filament\Pages\Settings\LogsSettingsUpdates;
use App\Settings\AccountProtectionSettings;
use App\Settings\AuthenticationSettings;
use App\Settings\LoginSecuritySettings;
use App\Settings\PasswordPolicySettings;
use App\Settings\RegistrationSettings;
use App\Settings\SessionSettings;

/**
 * Reuses the same LogsSettingsUpdates::saveSettingsWithAudit() helper
 * every other settings page routes through — routing field-level diffs
 * through AuditTrailService rather than calling `activity('security')`
 * directly (per the project's "never activity() directly in business
 * code" rule), with an atomic settings+audit commit under 'security'
 * log_name / 'settings_updated' event, and any future
 * password/secret/token-shaped Security field automatically gets
 * presence-only redaction instead of silently being dropped from the
 * diff.
 */
class SecuritySettingsService
{
    use LogsSettingsUpdates;

    /** @param array<string, mixed> $data */
    public function saveAuthentication(array $data): bool
    {
        return $this->saveSettingsWithAudit(AuthenticationSettings::class, 'security', function (AuthenticationSettings $settings) use ($data): void {
            $settings->login_enabled = (bool) ($data['login_enabled'] ?? true);
            $settings->remember_me_enabled = (bool) ($data['remember_me_enabled'] ?? true);
            $settings->email_verification_required = (bool) ($data['email_verification_required'] ?? true);
            $settings->default_login_method = $data['default_login_method'] ?? 'email';
            $settings->social_login_enabled = (bool) ($data['social_login_enabled'] ?? false);
        });
    }

    /** @param array<string, mixed> $data */
    public function savePasswordPolicy(array $data): bool
    {
        return $this->saveSettingsWithAudit(PasswordPolicySettings::class, 'security', function (PasswordPolicySettings $settings) use ($data): void {
            $settings->min_length = max(1, (int) ($data['min_length'] ?? 8));
            $settings->require_uppercase = (bool) ($data['require_uppercase'] ?? true);
            $settings->require_lowercase = (bool) ($data['require_lowercase'] ?? true);
            $settings->require_number = (bool) ($data['require_number'] ?? true);
            $settings->require_special = (bool) ($data['require_special'] ?? false);
            $settings->prevent_reuse = (bool) ($data['prevent_reuse'] ?? false);
            $settings->password_history_count = max(1, (int) ($data['password_history_count'] ?? 5));
            $settings->expiry_enabled = (bool) ($data['expiry_enabled'] ?? false);
            $settings->expiry_days = max(1, (int) ($data['expiry_days'] ?? 90));
            $settings->force_change_on_first_login = (bool) ($data['force_change_on_first_login'] ?? false);
        });
    }

    /** @param array<string, mixed> $data */
    public function saveLoginSecurity(array $data): bool
    {
        return $this->saveSettingsWithAudit(LoginSecuritySettings::class, 'security', function (LoginSecuritySettings $settings) use ($data): void {
            $settings->max_failed_attempts = max(1, (int) ($data['max_failed_attempts'] ?? 5));
            $settings->lockout_duration = max(1, (int) ($data['lockout_duration'] ?? 15));
            $settings->throttling_enabled = (bool) ($data['throttling_enabled'] ?? true);
            $settings->reset_throttling_enabled = (bool) ($data['reset_throttling_enabled'] ?? true);
            $settings->notify_user_on_failed = (bool) ($data['notify_user_on_failed'] ?? true);
            $settings->notify_admin_on_lock = (bool) ($data['notify_admin_on_lock'] ?? false);
        });
    }

    /** @param array<string, mixed> $data */
    public function saveSession(array $data): bool
    {
        return $this->saveSettingsWithAudit(SessionSettings::class, 'security', function (SessionSettings $settings) use ($data): void {
            $settings->idle_timeout = max(1, (int) ($data['idle_timeout'] ?? 120));
            $settings->allow_multiple_sessions = (bool) ($data['allow_multiple_sessions'] ?? true);
            $settings->force_logout_on_password_change = (bool) ($data['force_logout_on_password_change'] ?? true);
        });
    }

    /** @param array<string, mixed> $data */
    public function saveRegistration(array $data): bool
    {
        return $this->saveSettingsWithAudit(RegistrationSettings::class, 'security', function (RegistrationSettings $settings) use ($data): void {
            $settings->self_registration_enabled = (bool) ($data['self_registration_enabled'] ?? false);
            $settings->default_role = $data['default_role'] ?? null;
            $settings->require_admin_approval = (bool) ($data['require_admin_approval'] ?? false);
            $settings->send_welcome_email = (bool) ($data['send_welcome_email'] ?? true);
            $settings->auto_verify_email = (bool) ($data['auto_verify_email'] ?? false);
        });
    }

    /** @param array<string, mixed> $data */
    public function saveAccountProtection(array $data): bool
    {
        return $this->saveSettingsWithAudit(AccountProtectionSettings::class, 'security', function (AccountProtectionSettings $settings) use ($data): void {
            $settings->disable_after_failed_attempts = (bool) ($data['disable_after_failed_attempts'] ?? true);
            $settings->auto_unlock_after = max(0, (int) ($data['auto_unlock_after'] ?? 30));
            $settings->notify_user = (bool) ($data['notify_user'] ?? true);
            $settings->notify_admin = (bool) ($data['notify_admin'] ?? false);
        });
    }
}
