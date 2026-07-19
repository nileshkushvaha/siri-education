<?php

declare(strict_types=1);

namespace App\Enums;

use App\Settings\AccountProtectionSettings;
use App\Settings\LoginSecuritySettings;

enum LoginResult: string
{
    case Success = 'success';
    case InvalidCredentials = 'invalid_credentials';
    case AccountLocked = 'account_locked';
    case AccountBlocked = 'account_blocked';
    case EmailUnverified = 'email_unverified';
    case AccountInactive = 'account_inactive';
    case AdminAccountOnly = 'admin_account_only';
    case StudentAccountRestricted = 'student_account_restricted';

    public function message(): string
    {
        return match ($this) {
            self::Success => 'Welcome back!',
            self::InvalidCredentials => 'These credentials do not match our records.',
            self::AccountLocked => (static function (): string {
                $ap = app(AccountProtectionSettings::class);
                if ($ap->disable_after_failed_attempts && $ap->auto_unlock_after === 0) {
                    return 'Your account has been locked. Please check your email for an unlock link, or contact an administrator.';
                }
                $minutes = $ap->disable_after_failed_attempts && $ap->auto_unlock_after > 0
                    ? $ap->auto_unlock_after
                    : app(LoginSecuritySettings::class)->lockout_duration;

                return 'Your account has been temporarily locked due to too many failed login attempts. Please check your email to unlock it, or try again in '.$minutes.' minutes.';
            })(),
            self::AccountBlocked => 'Your account has been suspended. Please contact support.',
            self::EmailUnverified => 'Please verify your email address before signing in.',
            self::AccountInactive => 'Your account is inactive. Please contact support.',
            self::AdminAccountOnly => 'These credentials do not match our records.',
            // Deliberately as generic as the other account-status messages
            // — never names "suspended"/"archived" or exposes the admin's
            // recorded reason on the login screen (Phase 24H Step 8).
            self::StudentAccountRestricted => 'Your account is not available. Please contact support.',
        };
    }

    public function isSuccessful(): bool
    {
        return $this === self::Success;
    }
}
