<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\Auth\EmailVerificationCodeNotification;
use App\Services\Auth\LoginService;
use App\Services\Auth\VerificationResendService;
use App\Settings\AuthenticationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * A freshly registered account is created `pending_verification`. It is
 * allowed past the status gate and into the credential check precisely so a
 * correct password can earn it a fresh verification code — that is the only
 * way out of the unverified state.
 *
 * The re-send must never become a way to mail an account an administrator has
 * shut down, nor a way to spam an address, and it must never fire for someone
 * who has not proven the password.
 */
class VerificationResendOnLoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct-Horse-9!';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        RateLimiter::clear('verification-resend:1');
    }

    private function register(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'status' => User::STATUS_PENDING,
            'email_verified_at' => null,
            'last_login_at' => null,
            'password' => bcrypt(self::PASSWORD),
        ], $overrides));
    }

    private function attempt(User $user, string $password = self::PASSWORD): void
    {
        app(LoginService::class)->attempt(
            email: $user->email,
            password: $password,
            remember: false,
            ipAddress: '127.0.0.1',
            userAgent: 'phpunit',
        );
    }

    // ── The stuck user gets unstuck ──────────────────────────────────

    public function test_a_new_registration_that_never_verified_is_sent_a_fresh_code(): void
    {
        $user = $this->register();

        $this->attempt($user);

        Notification::assertSentTo($user, EmailVerificationCodeNotification::class);
    }

    public function test_a_wrong_password_never_sends_a_code(): void
    {
        // The code is only issued after the credential check, so typing
        // somebody else's address cannot mail them anything.
        $user = $this->register();

        $this->attempt($user, 'not-the-password');

        // (A failed-attempt alert may still be sent — that is a different
        // notification and not a way back into the account.)
        Notification::assertNotSentTo($user, EmailVerificationCodeNotification::class);
    }

    public function test_an_account_awaiting_admin_approval_is_not_sent_a_code(): void
    {
        // STATUS_INACTIVE is rejected before the credential check: it is
        // either awaiting approval or switched off by an administrator, and
        // neither is unblocked by verifying an email address.
        $user = $this->register(['status' => User::STATUS_INACTIVE]);

        $this->attempt($user);

        Notification::assertNothingSentTo($user);
    }

    // ── Never for restricted accounts ────────────────────────────────

    public function test_a_blocked_account_is_never_sent_a_verification_code(): void
    {
        $user = $this->register(['status' => User::STATUS_BLOCKED]);

        $this->attempt($user);

        Notification::assertNothingSentTo($user);
    }

    public function test_a_suspended_account_is_never_sent_a_verification_code(): void
    {
        $user = $this->register(['status' => User::STATUS_SUSPENDED]);

        $this->attempt($user);

        Notification::assertNothingSentTo($user);
    }

    public function test_a_locked_account_is_never_sent_a_verification_code(): void
    {
        $user = $this->register([
            'locked_at' => now(),
            'locked_until' => now()->addHour(),
        ]);

        $this->attempt($user);

        Notification::assertNothingSentTo($user);
    }

    public function test_an_account_deactivated_after_use_is_never_re_invited(): void
    {
        // last_login_at is what separates "new registration" from "account an
        // administrator later switched off" — the latter must not be mailed a
        // fresh way back in.
        $user = $this->register([
            'status' => User::STATUS_INACTIVE,
            'last_login_at' => now()->subMonth(),
        ]);

        $this->attempt($user);

        Notification::assertNothingSentTo($user);
    }

    public function test_an_already_verified_account_is_not_sent_another_code(): void
    {
        $user = $this->register([
            'status' => User::STATUS_INACTIVE,
            'email_verified_at' => now(),
        ]);

        $this->attempt($user);

        Notification::assertNothingSentTo($user);
    }

    // ── Bounded ──────────────────────────────────────────────────────

    public function test_repeated_login_attempts_send_only_one_mail_per_window(): void
    {
        $user = $this->register();

        $this->attempt($user);
        $this->attempt($user);
        $this->attempt($user);

        Notification::assertSentToTimes($user, EmailVerificationCodeNotification::class, 1);
    }

    // ── The eligibility rule itself ──────────────────────────────────

    public function test_eligibility_requires_every_condition(): void
    {
        $service = app(VerificationResendService::class);

        $this->assertTrue($service->eligible($this->register()));
        $this->assertFalse($service->eligible(null));

        // Active but unverified is a legitimate re-send: that path runs after
        // the password check, so the requester is provably the owner — and it
        // stays eligible even once they have logged in before.
        $this->assertTrue($service->eligible($this->register(['status' => User::STATUS_ACTIVE])));
        $this->assertTrue($service->eligible($this->register([
            'status' => User::STATUS_ACTIVE,
            'last_login_at' => now(),
        ])));

        $this->assertFalse($service->eligible($this->register(['status' => User::STATUS_BLOCKED])));
        $this->assertFalse($service->eligible($this->register(['status' => User::STATUS_SUSPENDED])));
        $this->assertFalse($service->eligible($this->register(['email_verified_at' => now()])));

        // Only the ambiguous inactive state is gated on login history.
        $this->assertFalse($service->eligible($this->register([
            'status' => User::STATUS_INACTIVE,
            'last_login_at' => now(),
        ])));
    }

    public function test_a_verified_active_user_logging_in_normally_gets_no_verification_mail(): void
    {
        app(AuthenticationSettings::class);

        $user = $this->register([
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $this->attempt($user);

        Notification::assertNotSentTo($user, EmailVerificationCodeNotification::class);
    }
}
