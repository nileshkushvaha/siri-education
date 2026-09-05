<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\GoogleActivationResult;
use App\Enums\LoginResult;
use App\Exceptions\Auth\RegistrationException;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\PortalResolver;
use App\Settings\AuthenticationSettings;
use App\Settings\RegistrationSettings;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Google account ACTIVATION — not Google SSO.
 *
 * Google answers exactly one question: "does this person control the
 * verified Google account for this email?". Everything else — whether an
 * account exists, its status, roles, permissions, which portal it belongs
 * to — is decided by the local `users` row and the existing auth pipeline.
 *
 * Business flow (frontend-portal users only):
 *   first visit  → Continue with Google → identity verified → Google
 *                  subject linked → signed in via LoginService → forced
 *                  to create a local password (PasswordLifecycleService::
 *                  awaitingActivationPassword) → activated.
 *   later visits → email + password. Google is refused once activated.
 *
 * Fails closed at every step. Never creates users, never changes roles,
 * never reactivates an inactive/blocked/suspended account, never relinks
 * a Google subject that already belongs to another user.
 */
final class GoogleActivationService
{
    public const string LOGIN_METHOD = 'google';

    public function __construct(
        private readonly AuthenticationSettings $authSettings,
        private readonly PortalResolver $portal,
        private readonly LoginService $loginService,
        private readonly PasswordLifecycleService $passwordLifecycle,
        private readonly AccountEmailVerificationService $emailVerification,
        private readonly AuditTrailService $audit,
        private readonly RegistrationSettings $regSettings,
        private readonly RegistrationService $registration,
    ) {}

    /** Session key holding Google's locale hint (e.g. "en-IN") for the complete-profile prefill. */
    public const string LOCALE_HINT_SESSION_KEY = 'google_locale_hint';

    public function isEnabled(): bool
    {
        return $this->authSettings->login_enabled
            && $this->authSettings->social_login_enabled
            && filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    public function activate(SocialiteUser $google, string $ipAddress, string $userAgent, ?string $sessionId): GoogleActivationOutcome
    {
        if (! $this->isEnabled()) {
            return new GoogleActivationOutcome(GoogleActivationResult::Disabled);
        }

        $subject = trim((string) $google->getId());
        $email = strtolower(trim((string) $google->getEmail()));
        $raw = $google->getRaw();
        $emailVerified = filter_var($raw['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($subject === '' || $email === '' || ! $emailVerified) {
            return $this->rejectGuest(GoogleActivationResult::UnverifiedGoogleEmail, $email);
        }

        // The Google "sub" is the permanent link; email is only the
        // first-time match. Once linked, a changed Google email is fine —
        // and a matching email never overrides an existing, different link.
        $user = User::query()->where('google_subject', $subject)->first()
            ?? User::query()->where('email', $email)->first();

        $justRegistered = false;

        if ($user === null) {
            // Unknown address: the client's rule is "register them as a
            // student" — subject to the same admin registration toggles as
            // the register form. Never an instructor, never an admin role.
            if (! $this->regSettings->self_registration_enabled) {
                return $this->rejectGuest(GoogleActivationResult::NotRegistered, $email);
            }

            try {
                $user = $this->registerStudent($google, $email, $ipAddress, $userAgent);
            } catch (RegistrationException) {
                return $this->rejectGuest(GoogleActivationResult::NotRegistered, $email);
            }

            $justRegistered = true;
        }

        if ($user->google_subject !== null && $user->google_subject !== $subject) {
            return $this->reject($user, GoogleActivationResult::IdentityConflict, $email);
        }

        // Admin-portal roles authenticate through /admin/login only.
        if ($this->portal->usesAdminPortal($user)) {
            return $this->reject($user, GoogleActivationResult::AdminAccount, $email);
        }

        // Activation-only: once the account has been through Google AND is
        // no longer waiting for its password, Google is refused. A user who
        // linked but abandoned the set-password step may still come back.
        if ($user->google_linked_at !== null && ! $this->passwordLifecycle->awaitingActivationPassword($user)) {
            return $this->reject($user, GoogleActivationResult::AlreadyActivated, $email);
        }

        // Google has verified the very address this account registered
        // with — equivalent to entering the one-time code. The service
        // applies its own rules (only pending_verification activates).
        if ($user->status === User::STATUS_PENDING && ! $user->hasVerifiedEmail() && $email === strtolower((string) $user->email)) {
            $this->emailVerification->verifyAndActivate($user);
            $user->refresh();
        }

        $this->link($user, $subject, $email);

        // Approval-required registrations are INACTIVE until an admin
        // activates them: link the identity (done above) so the return
        // visit works, but do not sign them in.
        if ($justRegistered && $user->status === User::STATUS_INACTIVE) {
            return $this->reject($user, GoogleActivationResult::PendingApproval, $email);
        }

        $login = $this->loginService->completeVerifiedLogin($user, $ipAddress, $userAgent, $sessionId, self::LOGIN_METHOD);

        if (! $login->isSuccessful()) {
            return $this->reject($user, GoogleActivationResult::AccountUnavailable, $email, $login);
        }

        $this->rememberLocaleHint($raw);

        return new GoogleActivationOutcome(GoogleActivationResult::Success, $email);
    }

    /**
     * Create the student. Names come from Google's given_name/family_name
     * when present, else a best-effort split of the display name; a
     * missing name falls back to the mailbox part of the address.
     *
     * @param  array<string, mixed>  $raw
     */
    private function registerStudent(SocialiteUser $google, string $email, string $ipAddress, string $userAgent): User
    {
        $raw = $google->getRaw();
        $first = trim((string) ($raw['given_name'] ?? ''));
        $last = trim((string) ($raw['family_name'] ?? ''));

        if ($first === '') {
            $parts = preg_split('/\s+/', trim((string) $google->getName()), 2) ?: [];
            $first = trim((string) ($parts[0] ?? ''));
            $last = $last !== '' ? $last : trim((string) ($parts[1] ?? ''));
        }

        if ($first === '') {
            $first = ucfirst((string) strstr($email, '@', true));
        }

        $result = $this->registration->registerVerifiedExternal([
            'first_name' => mb_substr($first, 0, 100),
            'last_name' => $last !== '' ? mb_substr($last, 0, 100) : null,
            'email' => $email,
        ], $ipAddress, $userAgent, self::LOGIN_METHOD);

        $this->audit->logUser($result->user, 'auth', 'google_student_registered', 'Student account created from verified Google identity', $result->user, [
            'google_email' => $email,
            'requires_approval' => $result->requiresApproval,
            'login_method' => self::LOGIN_METHOD,
        ]);

        return $result->user;
    }

    /**
     * Google sometimes includes a BCP-47 locale ("en-IN"). It is only a
     * prefill hint for the complete-profile country picker — never a
     * decision. Stored in the session so it survives the redirect chain.
     *
     * @param  array<string, mixed>  $raw
     */
    private function rememberLocaleHint(array $raw): void
    {
        $locale = (string) ($raw['locale'] ?? '');

        if ($locale !== '' && session()->isStarted()) {
            session()->put(self::LOCALE_HINT_SESSION_KEY, $locale);
        }
    }

    private function link(User $user, string $subject, string $email): void
    {
        if ($user->google_subject === $subject) {
            return;
        }

        DB::transaction(function () use ($user, $subject, $email): void {
            $attributes = [
                'google_subject' => $subject,
                'google_email' => $email,
                'google_linked_at' => now(),
            ];

            // Never set their own password → creating one is the
            // activation step. An existing password-holder is linked
            // and signed in without being disturbed.
            $neverSetPassword = $user->password_changed_at === null;

            if ($neverSetPassword) {
                $attributes['must_change_password'] = true;
            }

            $user->forceFill($attributes)->saveQuietly();

            $this->audit->logUser($user, 'auth', 'google_account_linked', 'Google account linked for account activation', $user, [
                'google_email' => $email,
                'password_setup_required' => $neverSetPassword,
                'login_method' => self::LOGIN_METHOD,
            ]);
        });
    }

    private function reject(User $user, GoogleActivationResult $result, string $googleEmail, ?LoginResult $loginResult = null): GoogleActivationOutcome
    {
        $this->audit->logSystem('auth', 'google_login_rejected', 'Google sign-in rejected: '.$result->value, $user, array_filter([
            'reason' => $result->value,
            'login_result' => $loginResult?->value,
            'google_email' => $googleEmail,
            'login_method' => self::LOGIN_METHOD,
        ]));

        return new GoogleActivationOutcome($result, $googleEmail, $loginResult);
    }

    private function rejectGuest(GoogleActivationResult $result, string $googleEmail): GoogleActivationOutcome
    {
        $this->audit->logGuest('auth', 'google_login_rejected', 'Google sign-in rejected: '.$result->value, null, '', $googleEmail, '', [
            'reason' => $result->value,
            'login_method' => self::LOGIN_METHOD,
        ]);

        return new GoogleActivationOutcome($result, $googleEmail);
    }
}
