<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\Auth\AccountLockedNotification;
use App\Notifications\Auth\AccountUnlockedNotification;
use App\Notifications\Auth\AdminAccountLockedNotification;
use App\Settings\AccountProtectionSettings;

/**
 * Manages the account-level lock lifecycle: auto-unlock on expiry,
 * administrator-initiated manual lock and unlock.
 *
 * Login-Security lockout (failed-attempt thresholds, per-attempt counters)
 * lives in LoginSecurityService. This service handles everything AFTER the
 * lock is established and anything requiring administrator intervention.
 *
 * Architecture notes for future extensions:
 *  - Suspicious login detection can set lock_reason = 'suspicious_activity'
 *  - IP violation can set lock_reason = 'ip_violation'
 *  - Device restriction can set lock_reason = 'device_violation'
 *  - Risk engine can call manualLock() with a descriptive reason string
 *  - Login history, device trust, and adaptive auth check this service's
 *    isLockReasonReversible() before auto-unlocking
 */
final class AccountProtectionService
{
    public function __construct(
        private readonly AccountProtectionSettings $settings,
    ) {}

    /**
     * Called when a login attempt finds that a new-style lock has auto-expired.
     * Resets the failed-attempt counter so the user gets fresh attempts.
     */
    public function processAutoUnlock(User $user, string $ipAddress): void
    {
        $user->updateQuietly([
            'failed_login_count' => 0,
            'locked_at' => null,
            'locked_until' => null,
            'lock_reason' => null,
            'unlock_token' => null,
            'unlock_token_expires_at' => null,
        ]);

        activity('auth')
            ->causedBy($user)
            ->performedOn($user)
            ->event('auto_unlock')
            ->withProperties(['ip' => $ipAddress, 'reason' => 'lock_duration_expired'])
            ->log('Account auto-unlocked: lock duration expired');

        if ($this->settings->notify_user) {
            $user->notify(new AccountUnlockedNotification('auto'));
        }
    }

    /**
     * Administrator locks an account manually.
     * Sets locked_until = null (manual unlock required — no auto-expiry).
     */
    public function manualLock(User $user, ?User $actor = null, string $reason = 'manual_admin'): void
    {
        $user->updateQuietly([
            'locked_at' => now(),
            'locked_until' => null,
            'lock_reason' => $reason,
            'unlock_token' => null,
            'unlock_token_expires_at' => null,
        ]);

        activity('auth')
            ->causedBy($actor ?? $user)
            ->performedOn($user)
            ->event('manual_lock')
            ->withProperties([
                'actor_id' => $actor?->id,
                'reason' => $reason,
            ])
            ->log('Account manually locked by administrator');

        if ($this->settings->notify_user) {
            $user->notify(new AccountLockedNotification('', 0));
        }

        if ($this->settings->notify_admin && $actor && $actor->id !== $user->id) {
            // Phase 24T: whereHasRoleNamed(), not role() — a missing
            // super_admin role must never crash the manual-lock action
            // itself; it should just mean "no one to notify".
            User::query()
                ->whereHasRoleNamed('super_admin')
                ->where('id', '!=', $user->id)
                ->each(function (User $admin) use ($user, $actor): void {
                    $admin->notify(new AdminAccountLockedNotification($user, $actor?->email ?? 'admin'));
                });
        }
    }

    /**
     * Administrator (or self-service email) unlocks an account.
     */
    public function manualUnlock(User $user, ?User $actor = null, string $method = 'admin'): void
    {
        $user->unlock();

        $event = $method === 'self_service' ? 'self_service_unlock' : 'manual_unlock';

        activity('auth')
            ->causedBy($actor ?? $user)
            ->performedOn($user)
            ->event($event)
            ->withProperties([
                'actor_id' => $actor?->id,
                'method' => $method,
            ])
            ->log('Account unlocked: '.$method);

        if ($this->settings->notify_user) {
            $user->notify(new AccountUnlockedNotification($method));
        }
    }
}
