<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Decides whether a login attempt should re-send the verification email, and
 * sends it.
 *
 * The problem this solves: a freshly registered account is created
 * `pending_verification`, and LoginService rejects non-active accounts BEFORE
 * the credential check. So someone who lost the original verification mail hit
 * "Your account is inactive. Please contact support." with no way forward —
 * they could not verify, and could not log in to ask for a new link.
 *
 * Eligibility is deliberately narrow. Re-sending must never be a way to mail
 * an account an administrator has shut down, so it requires ALL of:
 *
 *   - the email is still unverified — otherwise there is nothing to re-send
 *   - not blocked or suspended (User::isBlocked() covers both)
 *   - not locked out by the account-protection rules
 *   - and the status permits it:
 *       active   — always; this path runs after the password check
 *       pending  — always; the brand-new registration this exists for
 *       inactive — only when the account has never logged in, which is what
 *                  distinguishes "awaiting admin approval" from "an admin
 *                  switched this off"; the latter must not be re-invited
 *
 * A deleted account cannot reach here at all: users are hard-deleted, so the
 * lookup simply finds nothing.
 *
 * The per-user cooldown matters because the inactive branch of LoginService
 * runs BEFORE the password is checked — this is reachable by anyone who types
 * a registered address. That is the same exposure the existing public
 * "resend verification" endpoint already carries, and the cooldown bounds it
 * to one mail per account per window no matter how many attempts arrive.
 */
final class VerificationResendService
{
    /** One verification mail per account per window, however many logins are attempted. */
    public const int COOLDOWN_SECONDS = 900;

    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    public function eligible(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        // Nothing to re-send, whatever the status.
        if ($user->hasVerifiedEmail()) {
            return false;
        }

        // Blocked, suspended or locked out: never, in any state.
        if ($user->isBlocked() || $user->isLocked()) {
            return false;
        }

        return match ($user->status) {
            // A live account that simply has not verified yet. It reaches this
            // via the EmailUnverified branch, which runs AFTER the password
            // check, so the requester is provably the owner — no further
            // restriction is warranted, including for accounts that logged in
            // before verification was required.
            User::STATUS_ACTIVE => true,

            // A brand-new registration: exactly the stuck case.
            User::STATUS_PENDING => true,

            // Ambiguous — it is either a registration awaiting admin approval
            // or an account an administrator switched off. A login history is
            // what tells them apart, and only the former may be mailed a fresh
            // way back in.
            User::STATUS_INACTIVE => $user->last_login_at === null,

            default => false,
        };
    }

    /**
     * Sends the verification email if the account qualifies and the cooldown
     * has elapsed.
     *
     * @return bool whether a mail was actually queued — callers must not use
     *              this to vary their response, or it becomes an account
     *              enumeration oracle
     */
    public function resendIfEligible(?User $user): bool
    {
        if (! $this->eligible($user)) {
            return false;
        }

        $key = 'verification-resend:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return false;
        }

        RateLimiter::hit($key, self::COOLDOWN_SECONDS);

        $user->sendEmailVerificationNotification();

        $this->audit->logSystem(
            'auth',
            'verification_email_resent',
            'Verification email re-sent after a login attempt on an unverified account.',
            $user,
            ['user_id' => $user->getKey()],
        );

        return true;
    }
}
