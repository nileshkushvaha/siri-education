<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\Auth\AccountLockedNotification;
use App\Notifications\Auth\AdminAccountLockedNotification;
use App\Notifications\Auth\FailedLoginAttemptNotification;
use App\Settings\AccountProtectionSettings;
use App\Settings\LoginSecuritySettings;
use Illuminate\Support\Str;

final class LoginSecurityService
{
    public function __construct(
        private readonly LoginSecuritySettings $settings,
        private readonly AccountProtectionSettings $accountProtection,
    ) {}

    /**
     * Record a failed credential attempt for a known user.
     *
     * Locking only occurs when AccountProtectionSettings::disable_after_failed_attempts
     * is enabled — this is the single source of truth for whether accounts are locked.
     *
     * Lock duration comes from AccountProtectionSettings::auto_unlock_after (minutes).
     * When auto_unlock_after = 0, the account requires manual administrator unlock
     * (locked_until is set to null — no automatic expiry).
     *
     * Returns remaining attempts before lockout, or 0 when the account was just locked.
     */
    public function recordFailedAttempt(User $user, string $ipAddress): int
    {
        $newCount = $user->failed_login_count + 1;
        $willLock = $this->accountProtection->disable_after_failed_attempts
            && $newCount >= $this->settings->max_failed_attempts;

        if ($willLock) {
            $token = Str::random(64);
            $autoUnlockMinutes = $this->accountProtection->auto_unlock_after;

            $user->updateQuietly([
                'failed_login_count' => $newCount,
                'locked_at' => now(),
                // null = manual unlock only; future timestamp = auto-unlock
                'locked_until' => $autoUnlockMinutes > 0
                    ? now()->addMinutes($autoUnlockMinutes)
                    : null,
                'lock_reason' => 'failed_attempts',
                'unlock_token' => hash('sha256', $token),
                'unlock_token_expires_at' => now()->addMinutes(User::UNLOCK_TOKEN_MINUTES),
            ]);

            // Unlock email is always sent so the user can self-service regardless of notify_user
            if ($this->accountProtection->notify_user) {
                $user->notify(new AccountLockedNotification($token, $newCount));
            }

            // Admin alert — now authoritative in AccountProtectionSettings
            if ($this->accountProtection->notify_admin) {
                $this->notifyAdminsOfLock($user, $ipAddress);
            }

            activity('auth')
                ->causedBy($user)
                ->performedOn($user)
                ->event('account_locked')
                ->withProperties([
                    'ip' => $ipAddress,
                    'failed_attempts' => $newCount,
                    'lockout_minutes' => $autoUnlockMinutes,
                    'manual_unlock_required' => $autoUnlockMinutes === 0,
                ])
                ->log('Account locked after too many failed login attempts');

            return 0;
        }

        $user->updateQuietly(['failed_login_count' => $newCount]);

        $remaining = max(0, $this->settings->max_failed_attempts - $newCount);

        // Per-attempt notification — controlled by LoginSecuritySettings (separate concern)
        if ($this->settings->notify_user_on_failed) {
            $user->notify(new FailedLoginAttemptNotification(
                remainingAttempts: $remaining,
                ipAddress: $ipAddress,
            ));
        }

        return $remaining;
    }

    /**
     * Remaining attempts before lockout for the given user.
     */
    public function remainingAttempts(User $user): int
    {
        return max(0, $this->settings->max_failed_attempts - $user->failed_login_count);
    }

    /**
     * Whether login throttling is currently enabled via settings.
     */
    public function isThrottlingEnabled(): bool
    {
        return $this->settings->throttling_enabled;
    }

    /**
     * Whether password-reset throttling is currently enabled via settings.
     */
    public function isResetThrottlingEnabled(): bool
    {
        return $this->settings->reset_throttling_enabled;
    }

    // ── Private ───────────────────────────────────────────────────────

    private function notifyAdminsOfLock(User $lockedUser, string $ipAddress): void
    {
        // whereHasRoleNamed(), not role() — a missing
        // super_admin role must never crash the failed-login-handling
        // path itself; it should just mean "no one to notify".
        User::query()
            ->whereHasRoleNamed('super_admin')
            ->where('id', '!=', $lockedUser->id)
            ->each(function (User $admin) use ($lockedUser, $ipAddress): void {
                $admin->notify(new AdminAccountLockedNotification($lockedUser, $ipAddress));
            });
    }
}
