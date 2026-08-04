<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\Auth\EmailVerificationOutcome;
use App\Models\EmailVerificationChallenge;
use App\Models\User;
use App\Notifications\Auth\EmailVerificationCodeNotification;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Owns the one-time-code email verification challenge: issuing a code,
 * mailing it, and checking what the user typed back.
 *
 * It deliberately does NOT decide what "verified" means for the account —
 * marking the email verified, activating the account, running the student
 * lifecycle transition and writing the audit entry all stay in
 * AccountEmailVerificationService, which this service calls once the code
 * has been proven correct. That keeps a single owner for the
 * unverified → verified transition.
 *
 * Challenge rules mirror the phone OTP flow (PhoneVerificationService):
 * the code is stored hashed, only the newest un-consumed challenge counts,
 * issuing a new code invalidates the previous one, and each challenge has
 * a hard attempt ceiling on top of the per-IP rate limit.
 */
final class EmailVerificationOtpService
{
    public const int CODE_TTL_MINUTES = 15;

    private const int MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly AccountEmailVerificationService $verification,
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * Issue a fresh code and mail it.
     *
     * Callers are responsible for their own throttling (the routes and
     * Livewire components that expose "resend" already apply it); this
     * method is also called from queued listeners, where throwing a
     * validation error would be meaningless.
     */
    public function issue(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $fingerprint = $this->fingerprint($user->getEmailForVerification());

        $this->invalidateOutstanding($user);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        EmailVerificationChallenge::query()->create([
            'user_id' => $user->getKey(),
            'email_fingerprint' => $fingerprint,
            'code_hash' => Hash::make($code),
            'attempts_remaining' => self::MAX_ATTEMPTS,
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
        ]);

        $user->notify(new EmailVerificationCodeNotification($code, self::CODE_TTL_MINUTES));

        $this->audit->logSystem(
            'auth',
            'email_otp_sent',
            'Email verification code sent.',
            $user,
            ['expires_in_minutes' => self::CODE_TTL_MINUTES],
        );
    }

    /**
     * Check a submitted code and, when it matches, verify the account.
     *
     * @throws ValidationException on a wrong, expired, exhausted or missing code
     */
    public function verify(User $user, string $code, string $ip): EmailVerificationOutcome
    {
        $key = "email-otp-verify:{$user->getKey()}:{$ip}";

        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages([
                'code' => 'Too many attempts. Please request a new code and try again shortly.',
            ]);
        }

        RateLimiter::hit($key, 600);

        $fingerprint = $this->fingerprint($user->getEmailForVerification());

        // The failure path must NOT throw from inside the transaction:
        // that would roll back the attempt-counter decrement along with
        // it, and the per-challenge ceiling would never bite.
        $failedChallengeId = DB::transaction(function () use ($user, $code, $fingerprint): ?int {
            /** @var EmailVerificationChallenge|null $challenge */
            $challenge = EmailVerificationChallenge::query()
                ->where('user_id', $user->getKey())
                ->where('email_fingerprint', $fingerprint)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $valid = $challenge !== null
                && $challenge->expires_at->isFuture()
                && $challenge->attempts_remaining > 0
                && preg_match('/^\d{6}$/', $code) === 1
                && Hash::check($code, $challenge->code_hash);

            if (! $valid) {
                // -1 stands for "nothing to charge the attempt against".
                return $challenge?->getKey() ?? -1;
            }

            $challenge->update(['consumed_at' => now()]);

            return null;
        });

        if ($failedChallengeId !== null) {
            if ($failedChallengeId > 0) {
                EmailVerificationChallenge::query()
                    ->whereKey($failedChallengeId)
                    ->where('attempts_remaining', '>', 0)
                    ->decrement('attempts_remaining');
            }

            throw ValidationException::withMessages([
                'code' => 'That code is incorrect or has expired. Request a new one if you need to.',
            ]);
        }

        RateLimiter::clear($key);

        // Single owner of the unverified → verified transition.
        return $this->verification->verifyAndActivate($user);
    }

    public function invalidateOutstanding(User $user): void
    {
        EmailVerificationChallenge::query()
            ->where('user_id', $user->getKey())
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->update(['invalidated_at' => now()]);
    }

    /**
     * Binds a challenge to the address it was issued for, without storing
     * the address a second time: change the email and the outstanding code
     * stops matching.
     */
    private function fingerprint(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower($email), (string) config('app.key'));
    }
}
