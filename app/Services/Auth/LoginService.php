<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Actions\Auth\AttemptLoginAction;
use App\Enums\LoginResult;
use App\Events\Auth\LoginFailed;
use App\Events\Auth\UserLoggedIn;
use App\Models\LoginHistory;
use App\Models\User;
use App\Notifications\Auth\NewDeviceLoginNotification;
use App\Notifications\Auth\SuspiciousLoginNotification;
use App\Services\Student\StudentLifecycleService;
use App\Settings\AuthenticationSettings;
use App\Support\PendingEmailVerification;
use App\Support\UserAgentParser;

final class LoginService
{
    public function __construct(
        private readonly AttemptLoginAction $attemptLogin,
        private readonly AuthenticationSettings $authSettings,
        private readonly LoginSecurityService $loginSecurity,
        private readonly AccountProtectionService $accountProtection,
        private readonly StudentLifecycleService $studentLifecycle,
        private readonly VerificationResendService $verificationResend,
        private readonly LoginChallengeService $loginChallenge,
    ) {}

    public function attempt(
        string $email,
        string $password,
        bool $remember,
        string $ipAddress,
        string $userAgent,
        ?string $sessionId = null,
        string $loginMethod = 'password',
    ): LoginResult {
        $user = User::where('email', strtolower($email))->first();

        // Pre-flight checks before touching Auth guard
        if ($user) {
            $rejected = $this->preflight($user, $email, $ipAddress, $userAgent, $sessionId);

            if ($rejected !== null) {
                return $rejected;
            }
        }

        // Strip remember flag when the feature is disabled server-side
        if (! $this->authSettings->remember_me_enabled) {
            $remember = false;
        }

        // Credential check (no side-effects inside the action)
        $result = $this->attemptLogin->execute($email, $password, $remember);

        if (! $result->isSuccessful()) {
            // Track the failure (lock / notify / log) for known users
            if ($user) {
                $remaining = $this->loginSecurity->recordFailedAttempt($user, $ipAddress);
                session()->flash('login_remaining_attempts', $remaining);
            }

            // Only genuine credential failures raise the challenge — an
            // account-status rejection is not evidence of guessing.
            $this->loginChallenge->recordFailure($ipAddress);

            LoginFailed::dispatch($user, $email, $ipAddress, $userAgent, $result->value, $sessionId);

            return $result;
        }

        /** @var User $authenticated */
        $authenticated = auth()->user();

        // Check email verification AFTER successful credential check.
        // A pending_verification account is always handled here, whatever
        // the setting says — it is the state registration leaves behind,
        // and there is no other way out of it.
        if (! $authenticated->hasVerifiedEmail()
            && ($this->authSettings->email_verification_required || $this->isAwaitingEmailVerification($authenticated))) {
            auth()->logout();

            // Reached only after a correct password, so this is provably the
            // account owner asking. Same cooldown applies.
            $this->verificationResend->resendIfEligible($authenticated);

            // The password is proven, so this session may finish
            // verification and be signed in by the code screen.
            PendingEmailVerification::remember($authenticated);

            LoginFailed::dispatch($authenticated, $email, $ipAddress, $userAgent, LoginResult::EmailUnverified->value, $sessionId);

            return LoginResult::EmailUnverified;
        }

        return $this->finishLogin($authenticated, $ipAddress, $userAgent, $remember, $sessionId, $loginMethod);
    }

    /**
     * Sign in a user whose identity has ALREADY been proven by something
     * other than a password (today: Google account activation, see
     * GoogleActivationService). Runs exactly the same account-status gates
     * and success side effects as attempt() — login history, alerts,
     * lock reset, UserLoggedIn — so the two doors are indistinguishable
     * downstream except for `login_method`.
     *
     * Deliberately never "remembers" the session and never touches email
     * verification: the caller must have settled that before arriving.
     */
    public function completeVerifiedLogin(
        User $user,
        string $ipAddress,
        string $userAgent,
        ?string $sessionId,
        string $loginMethod,
    ): LoginResult {
        $rejected = $this->preflight($user, $user->email, $ipAddress, $userAgent, $sessionId);

        if ($rejected !== null) {
            return $rejected;
        }

        auth()->login($user);

        return $this->finishLogin($user, $ipAddress, $userAgent, false, $sessionId, $loginMethod);
    }

    // ── Private ───────────────────────────────────────────────────────

    /**
     * Account-status gates shared by every login door. Returns the
     * rejecting LoginResult (after dispatching LoginFailed) or null when
     * the account may proceed to a credential/identity check.
     */
    private function preflight(User $user, string $email, string $ipAddress, string $userAgent, ?string $sessionId): ?LoginResult
    {
        // Auto-unlock: new-style lock (locked_at set) whose duration has expired.
        // This resets the failed-attempt counter so the user gets fresh attempts,
        // and logs the auto-unlock event for the audit trail.
        if ($user->locked_at !== null && ! $user->isLocked()) {
            $this->accountProtection->processAutoUnlock($user, $ipAddress);
            $user->refresh();
        }

        if ($user->isLocked()) {
            LoginFailed::dispatch($user, $email, $ipAddress, $userAgent, LoginResult::AccountLocked->value, $sessionId);

            return LoginResult::AccountLocked;
        }

        if ($user->isBlocked()) {
            LoginFailed::dispatch($user, $email, $ipAddress, $userAgent, LoginResult::AccountBlocked->value, $sessionId);

            return LoginResult::AccountBlocked;
        }

        // A brand-new registration (status pending_verification, email
        // not yet verified) is NOT rejected here. It falls through to
        // the credential check so the unverified branch in attempt() runs
        // *after* the password is proven — which is what lets that
        // branch issue a verification code and hand the visitor the
        // code screen. Every other inactive state is still a dead stop.
        if (! $user->isActive() && ! $this->isAwaitingEmailVerification($user)) {
            LoginFailed::dispatch($user, $email, $ipAddress, $userAgent, LoginResult::AccountInactive->value, $sessionId);

            return LoginResult::AccountInactive;
        }

        // A suspended/archived student_status blocks login for the
        // whole account, regardless of any other role — see
        // StudentLifecycleService::blocksLogin() for the exact rule.
        if ($this->studentLifecycle->blocksLogin($user)) {
            LoginFailed::dispatch($user, $email, $ipAddress, $userAgent, LoginResult::StudentAccountRestricted->value, $sessionId);

            return LoginResult::StudentAccountRestricted;
        }

        return null;
    }

    /** Success side effects common to every login door. */
    private function finishLogin(User $user, string $ipAddress, string $userAgent, bool $remember, ?string $sessionId, string $loginMethod): LoginResult
    {
        $this->loginChallenge->clear($ipAddress);
        $user->recordSuccessfulLogin($ipAddress, $userAgent);

        // Login alert emails (if enabled)
        $this->dispatchLoginAlerts($user, $ipAddress, $userAgent);

        UserLoggedIn::dispatch($user, $ipAddress, $userAgent, $remember, $sessionId, $loginMethod);

        return LoginResult::Success;
    }

    /**
     * The state registration leaves an account in until its email is
     * verified. Deliberately narrow: an account an administrator switched
     * off (STATUS_INACTIVE) must never be routed into this flow.
     */
    private function isAwaitingEmailVerification(User $user): bool
    {
        return $user->status === User::STATUS_PENDING && ! $user->hasVerifiedEmail();
    }

    private function dispatchLoginAlerts(User $user, string $ip, string $ua): void
    {
        if (! $user->login_alerts_enabled && ! $user->new_device_alerts_enabled) {
            return;
        }

        $parsed = UserAgentParser::parse($ua);
        $loginAt = now()->format('d M Y, h:i A T');

        // Checked before this login is recorded to login_histories (that
        // happens later, via the UserLoggedIn -> LogLoginActivity listener),
        // so this only ever matches prior logins, never the current one.
        $isNewDevice = $user->new_device_alerts_enabled
            && $this->isNewDevice($user, $parsed['browser'], $parsed['platform']);

        if ($user->login_alerts_enabled) {
            $user->notify(new SuspiciousLoginNotification($ip, $parsed['browser'], $parsed['platform'], $loginAt));
        }

        if ($isNewDevice) {
            $user->notify(new NewDeviceLoginNotification($ip, $parsed['browser'], $parsed['platform'], $loginAt));
        }
    }

    private function isNewDevice(User $user, string $browser, string $platform): bool
    {
        return ! LoginHistory::query()
            ->where('user_id', $user->id)
            ->successful()
            ->where('browser', $browser)
            ->where('platform', $platform)
            ->exists();
    }
}
