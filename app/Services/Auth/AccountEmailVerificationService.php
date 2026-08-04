<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\Auth\EmailVerificationOutcome;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\Student\StudentLifecycleService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single authoritative owner of "email verification succeeded":
 * marking the email verified, deciding whether the account
 * may be activated, running the student lifecycle transition, and
 * recording the audit entry.
 *
 * It is deliberately independent of the HTTP session. Verification is a
 * property of the mailbox, not of the browser that happened to submit
 * the registration form, so the same call serves a guest who has just
 * entered a code and an already-authenticated user.
 *
 * ── Activation safety ────────────────────────────────────────────────
 * Registration creates exactly two statuses
 * (`RegistrationService::register()`):
 *
 *   STATUS_PENDING   — the normal flow; this is the ONLY status
 *                      verification is allowed to activate.
 *   STATUS_INACTIVE  — created when `require_admin_approval` is on.
 *                      An administrator must activate it; verification
 *                      must not do that job for them.
 *
 * `STATUS_INACTIVE` is therefore ambiguous — it is both "awaiting
 * approval" and the status an administrator sets to disable an account —
 * so this service never activates from it. Every other status
 * (BLOCKED, SUSPENDED, anything unrecognised) is likewise never
 * activated, which is what stops an old code from resurrecting an
 * account that has since been restricted.
 *
 * ── Concurrency ──────────────────────────────────────────────────────
 * The whole decision runs inside one transaction with the user row
 * locked, and the state is re-read after the lock. Two simultaneous
 * requests therefore serialise: the first performs the transition, the
 * second observes an already-verified user and returns
 * `AlreadyVerified` without dispatching `Verified` again or writing a
 * second audit entry. `StudentLifecycleService` applies the same
 * lock-then-check discipline to the profile row.
 */
final readonly class AccountEmailVerificationService
{
    /**
     * The only account status verification may activate.
     *
     * @see RegistrationService::register()
     */
    private const string ACTIVATABLE_STATUS = User::STATUS_PENDING;

    public function __construct(
        private StudentLifecycleService $studentLifecycle,
        private AuditTrailService $audit,
    ) {}

    /**
     * Verifies the email and, where the account's status permits,
     * activates it. Idempotent: safe to call repeatedly with the same
     * user.
     */
    public function verifyAndActivate(User $user): EmailVerificationOutcome
    {
        [$outcome, $justVerified, $fresh] = DB::transaction(function () use ($user): array {
            /** @var User|null $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return [EmailVerificationOutcome::UnsupportedAccountState, false, null];
            }

            // Already done by a concurrent request or an earlier click.
            if ($locked->hasVerifiedEmail()) {
                return [$this->outcomeForVerifiedAccount($locked), false, $locked];
            }

            $status = (string) $locked->status;

            // Fail closed on a status this workflow does not model:
            // leave the email alone rather than guess.
            if (! $this->isRecognisedStatus($status)) {
                Log::warning('Email verification reached an unrecognised account status; failing closed.', [
                    'user_id' => $locked->getKey(),
                    'status' => $status,
                ]);

                return [EmailVerificationOutcome::UnsupportedAccountState, false, $locked];
            }

            $locked->forceFill(['email_verified_at' => now()]);

            $activated = $status === self::ACTIVATABLE_STATUS;

            if ($activated) {
                $locked->forceFill(['status' => User::STATUS_ACTIVE]);
            }

            $locked->saveQuietly();

            $outcome = match (true) {
                $activated => EmailVerificationOutcome::VerifiedAndActivated,
                $status === User::STATUS_INACTIVE => EmailVerificationOutcome::VerifiedPendingApproval,
                default => EmailVerificationOutcome::VerifiedAccountRestricted,
            };

            $this->audit->logSystem(
                'auth',
                'email_verified',
                sprintf('Email address verified via one-time code (%s).', $outcome->value),
                $locked,
                [
                    'outcome' => $outcome->value,
                    'account_activated' => $activated,
                    'previous_status' => $status,
                ],
            );

            return [$outcome, true, $locked];
        });

        if ($fresh === null) {
            return $outcome;
        }

        // Outside the user-row transaction: the student lifecycle service
        // opens its own transaction and takes its own lock on the profile
        // row, and is itself Registered-only and idempotent. Nesting it
        // inside would hold the user lock for the duration of another
        // domain's write.
        if ($justVerified && $outcome === EmailVerificationOutcome::VerifiedAndActivated) {
            // Non-students have no `student_status`, so this is a no-op
            // for instructor and administrator accounts.
            $this->studentLifecycle->activateFromVerification($fresh);
        }

        // Exactly once, and only on a real unverified → verified
        // transition, so listeners such as the welcome notification
        // never fire twice for one account.
        if ($justVerified) {
            // `Verified` is a plain Laravel event without Dispatchable.
            event(new Verified($fresh));
        }

        // Keep the caller's instance consistent with what was persisted.
        $user->forceFill([
            'email_verified_at' => $fresh->email_verified_at,
            'status' => $fresh->status,
        ])->syncOriginal();

        return $outcome;
    }

    /**
     * An already-verified account still reports honestly on whether it
     * can actually be used, so the success page never tells a suspended
     * user their account is ready.
     */
    private function outcomeForVerifiedAccount(User $user): EmailVerificationOutcome
    {
        return match ((string) $user->status) {
            User::STATUS_ACTIVE => EmailVerificationOutcome::AlreadyVerified,
            User::STATUS_INACTIVE => EmailVerificationOutcome::VerifiedPendingApproval,
            User::STATUS_PENDING => EmailVerificationOutcome::AlreadyVerified,
            User::STATUS_BLOCKED, User::STATUS_SUSPENDED => EmailVerificationOutcome::VerifiedAccountRestricted,
            default => EmailVerificationOutcome::UnsupportedAccountState,
        };
    }

    private function isRecognisedStatus(string $status): bool
    {
        return in_array($status, [
            User::STATUS_PENDING,
            User::STATUS_ACTIVE,
            User::STATUS_INACTIVE,
            User::STATUS_BLOCKED,
            User::STATUS_SUSPENDED,
        ], true);
    }
}
