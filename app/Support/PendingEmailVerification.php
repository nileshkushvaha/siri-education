<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * Remembers which account the current browser session is allowed to
 * finish email verification for, so the one-time-code screen works for a
 * visitor who is not (yet) authenticated.
 *
 * Only two callers may set it, and both have already established that
 * the visitor owns the account:
 *
 *   - registration, in the same session that just created the account
 *   - login, AFTER the password check succeeded but the email is still
 *     unverified
 *
 * Nothing else grants it, so the code screen can never be pointed at a
 * stranger's account by typing their address. Entering the code then
 * proves control of the mailbox as well, which is what allows the
 * automatic sign-in that follows.
 *
 * Session-only, never persisted, and cleared the moment verification
 * completes or the visitor authenticates.
 */
final class PendingEmailVerification
{
    private const SESSION_KEY = 'auth.pending_email_verification_id';

    public static function remember(User $user): void
    {
        session([self::SESSION_KEY => $user->getKey()]);
    }

    public static function userId(): ?int
    {
        $id = session(self::SESSION_KEY);

        return is_numeric($id) ? (int) $id : null;
    }

    public static function user(): ?User
    {
        $id = self::userId();

        return $id === null ? null : User::query()->find($id);
    }

    public static function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}
