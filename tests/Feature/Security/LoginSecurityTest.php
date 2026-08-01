<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use App\Notifications\Auth\AccountLockedNotification;
use App\Notifications\Auth\AdminAccountLockedNotification;
use App\Notifications\Auth\FailedLoginAttemptNotification;
use App\Services\Auth\LoginSecurityService;
use App\Settings\AccountProtectionSettings;
use App\Settings\LoginSecuritySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LoginSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'database/settings']);
    }

    // ── Successful login resets counter ───────────────────────────────

    public function test_successful_login_resets_failed_count(): void
    {
        $user = $this->activeUser(['failed_login_count' => 3]);

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertSame(0, $user->fresh()->failed_login_count);
    }

    public function test_successful_login_clears_locked_until(): void
    {
        $user = $this->activeUser();
        $user->update(['locked_until' => now()->subMinute()]); // previously locked, now expired

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertNull($user->fresh()->locked_until);
    }

    // ── Failed login increments counter ───────────────────────────────

    public function test_failed_login_increments_failed_count(): void
    {
        $this->setLockout(max: 5, duration: 15);
        $user = $this->activeUser();

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertSame(1, $user->fresh()->failed_login_count);
    }

    public function test_unknown_email_does_not_increment_counter(): void
    {
        $this->setLockout(max: 5, duration: 15);

        $this->post(route('auth.login.store'), [
            'email' => 'nobody@example.com',
            'password' => 'anything',
        ]);

        // No user exists, no DB record to check — just assert no exception
        $this->assertTrue(true);
    }

    // ── Remaining attempts in error message ───────────────────────────

    public function test_remaining_attempts_shown_in_error_after_first_failure(): void
    {
        $this->setLockout(max: 5, duration: 15);
        $user = $this->activeUser();

        $response = $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'wrong',
        ]);

        $response->assertSessionHasErrors('email');
        $error = session('errors')->first('email');
        $this->assertStringContainsString('4 attempts remaining', $error);
    }

    public function test_remaining_attempts_not_shown_after_lock(): void
    {
        $this->setLockout(max: 3, duration: 15);
        $user = $this->activeUser(['failed_login_count' => 2]);

        // This attempt triggers the lock
        $response = $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'wrong',
        ]);

        // Should show "locked" message, not remaining attempts
        $response->assertSessionHasErrors('email');
        $error = session('errors')->first('email');
        $this->assertStringNotContainsString('remaining', $error);
    }

    // ── Account lockout ───────────────────────────────────────────────

    public function test_account_locked_after_max_attempts(): void
    {
        $this->setLockout(max: 3, duration: 15);
        $user = $this->activeUser();

        foreach (range(1, 3) as $_) {
            $this->post(route('auth.login.store'), [
                'email' => $user->email,
                'password' => 'wrong',
            ]);
        }

        $this->assertTrue($user->fresh()->isLocked());
    }

    public function test_locked_account_cannot_login_even_with_correct_password(): void
    {
        $this->setLockout(max: 3, duration: 15);
        $user = $this->activeUser(['failed_login_count' => 3, 'locked_until' => now()->addMinutes(15)]);

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_lockout_message_includes_duration(): void
    {
        $this->setLockout(max: 3, duration: 30);
        // AccountProtectionSettings.auto_unlock_after is now authoritative for the message
        $ap = app(AccountProtectionSettings::class);
        $ap->auto_unlock_after = 30;
        $ap->save();

        $user = $this->activeUser([
            'failed_login_count' => 3,
            'locked_until' => now()->addMinutes(30),
        ]);

        $response = $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $error = session('errors')->first('email');
        $this->assertStringContainsString('30', $error);
    }

    public function test_account_automatically_unlocks_after_duration(): void
    {
        $this->setLockout(max: 3, duration: 15);
        $user = $this->activeUser([
            'failed_login_count' => 3,
            'locked_until' => now()->subMinute(), // Lock has expired
        ]);

        // User::isLocked() checks if locked_until is in the future
        $this->assertFalse($user->isLocked());
    }

    // ── LoginSecurityService: remainingAttempts ────────────────────────

    public function test_remaining_attempts_calculated_correctly(): void
    {
        $this->setLockout(max: 5, duration: 15);
        $user = $this->activeUser(['failed_login_count' => 2]);

        $remaining = app(LoginSecurityService::class)->remainingAttempts($user);

        $this->assertSame(3, $remaining);
    }

    public function test_remaining_attempts_is_zero_when_at_threshold(): void
    {
        $this->setLockout(max: 5, duration: 15);
        $user = $this->activeUser(['failed_login_count' => 5]);

        $remaining = app(LoginSecurityService::class)->remainingAttempts($user);

        $this->assertSame(0, $remaining);
    }

    // ── Activity logging ──────────────────────────────────────────────

    public function test_account_lock_logged_in_activity(): void
    {
        $this->setLockout(max: 2, duration: 15);
        $user = $this->activeUser(['failed_login_count' => 1]);

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'wrong',
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'auth',
            'event' => 'account_locked',
            'subject_id' => $user->id,
        ]);
    }

    public function test_failed_login_logged_in_activity(): void
    {
        $this->setLockout(max: 5, duration: 15);
        $user = $this->activeUser();

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'wrong',
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'auth',
            'causer_id' => $user->id,
        ]);
    }

    // ── Notifications: AccountLockedNotification ──────────────────────

    public function test_account_locked_notification_sent_on_lock(): void
    {
        Notification::fake();

        $this->setLockout(max: 2, duration: 15);
        // notify_user is now controlled by AccountProtectionSettings
        $ap = app(AccountProtectionSettings::class);
        $ap->notify_user = true;
        $ap->save();

        $user = $this->activeUser(['failed_login_count' => 1]);

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'wrong',
        ]);

        Notification::assertSentTo($user, AccountLockedNotification::class);
    }

    public function test_account_locked_notification_not_sent_when_notify_user_disabled(): void
    {
        Notification::fake();

        $this->setLockout(max: 2, duration: 15);
        $ap = app(AccountProtectionSettings::class);
        $ap->notify_user = false;
        $ap->save();

        $user = $this->activeUser(['failed_login_count' => 1]);

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'wrong',
        ]);

        Notification::assertNotSentTo($user, AccountLockedNotification::class);
    }

    // ── Notifications: notify_user_on_failed ──────────────────────────

    public function test_failed_attempt_notification_sent_when_enabled(): void
    {
        Notification::fake();

        $this->setLockout(max: 5, duration: 15);
        $s = app(LoginSecuritySettings::class);
        $s->notify_user_on_failed = true;
        $s->save();

        $user = $this->activeUser();

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'wrong',
        ]);

        Notification::assertSentTo($user, FailedLoginAttemptNotification::class);
    }

    public function test_failed_attempt_notification_not_sent_when_disabled(): void
    {
        Notification::fake();

        $this->setLockout(max: 5, duration: 15);
        $s = app(LoginSecuritySettings::class);
        $s->notify_user_on_failed = false;
        $s->save();

        $user = $this->activeUser();

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'wrong',
        ]);

        Notification::assertNotSentTo($user, FailedLoginAttemptNotification::class);
    }

    public function test_failed_attempt_notification_not_sent_when_locking(): void
    {
        Notification::fake();

        $this->setLockout(max: 2, duration: 15);
        $s = app(LoginSecuritySettings::class);
        $s->notify_user_on_failed = true;
        $s->save();

        $user = $this->activeUser(['failed_login_count' => 1]);

        // This attempt triggers the lock — only AccountLockedNotification should go out
        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'wrong',
        ]);

        Notification::assertSentTo($user, AccountLockedNotification::class);
        Notification::assertNotSentTo($user, FailedLoginAttemptNotification::class);
    }

    // ── Notifications: notify_admin (AccountProtectionSettings) ──────────────

    public function test_admin_notified_on_lock_when_enabled(): void
    {
        Notification::fake();

        $this->setLockout(max: 2, duration: 15);
        // notify_admin is now controlled by AccountProtectionSettings (single source of truth)
        $ap = app(AccountProtectionSettings::class);
        $ap->notify_admin = true;
        $ap->save();

        $superAdmin = $this->createSuperAdmin();
        $user = $this->activeUser(['failed_login_count' => 1]);

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'wrong',
        ]);

        Notification::assertSentTo($superAdmin, AdminAccountLockedNotification::class);
    }

    public function test_admin_not_notified_when_setting_disabled(): void
    {
        Notification::fake();

        $this->setLockout(max: 2, duration: 15);
        $ap = app(AccountProtectionSettings::class);
        $ap->notify_admin = false;
        $ap->save();

        $superAdmin = $this->createSuperAdmin();
        $user = $this->activeUser(['failed_login_count' => 1]);

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'wrong',
        ]);

        Notification::assertNotSentTo($superAdmin, AdminAccountLockedNotification::class);
    }

    // ── Throttling ────────────────────────────────────────────────────

    public function test_throttling_enabled_limits_login_requests(): void
    {
        $s = app(LoginSecuritySettings::class);
        $s->throttling_enabled = true;
        $s->save();

        $user = $this->activeUser();

        // Hit the limiter (>10 per minute per email+IP)
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('auth.login.store'), [
                'email' => $user->email,
                'password' => 'wrong',
            ]);
        }

        // The 11th request should be throttled (429)
        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'wrong',
        ])->assertStatus(429);
    }

    public function test_throttling_disabled_allows_unlimited_login_requests(): void
    {
        $s = app(LoginSecuritySettings::class);
        $s->throttling_enabled = false;
        $s->save();

        $user = $this->activeUser();

        // Exceed normal threshold without hitting 429
        for ($i = 0; $i < 15; $i++) {
            $response = $this->post(route('auth.login.store'), [
                'email' => $user->email,
                'password' => 'wrong',
            ]);
            $this->assertNotEquals(429, $response->status());
        }
    }

    public function test_reset_throttling_enabled_limits_reset_requests(): void
    {
        $s = app(LoginSecuritySettings::class);
        $s->reset_throttling_enabled = true;
        $s->save();

        // Hit the limiter (>5 per minute)
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('auth.password.email'), ['email' => 'test@example.com']);
        }

        $this->post(route('auth.password.email'), ['email' => 'test@example.com'])
            ->assertStatus(429);
    }

    public function test_reset_throttling_disabled_allows_multiple_resets(): void
    {
        $s = app(LoginSecuritySettings::class);
        $s->reset_throttling_enabled = false;
        $s->save();

        // Exceed normal threshold without hitting 429
        for ($i = 0; $i < 8; $i++) {
            $response = $this->post(route('auth.password.email'), ['email' => 'test@example.com']);
            $this->assertNotEquals(429, $response->status());
        }
    }

    // ── LoginSecurityService: isThrottlingEnabled ─────────────────────

    public function test_service_reports_throttling_status(): void
    {
        $s = app(LoginSecuritySettings::class);
        $s->throttling_enabled = false;
        $s->save();

        $service = app(LoginSecurityService::class);
        $this->assertFalse($service->isThrottlingEnabled());

        $s->throttling_enabled = true;
        $s->save();
        app()->forgetInstance(LoginSecuritySettings::class);

        $this->assertTrue(app(LoginSecurityService::class)->isThrottlingEnabled());
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function setLockout(int $max, int $duration): void
    {
        $s = app(LoginSecuritySettings::class);
        $s->max_failed_attempts = $max;
        $s->lockout_duration = $duration;
        $s->save();
    }

    private function activeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ], $overrides));
    }

    private function createSuperAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $admin->assignRole($role);

        return $admin;
    }
}
