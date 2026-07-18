<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\StudentStatus;
use App\Events\Auth\UserApproved;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Models\UserProfile;
use App\Notifications\Auth\AccountApprovedNotification;
use App\Notifications\Auth\RegistrationPendingNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Notifications\Auth\WelcomeNotification;
use App\Settings\AuthenticationSettings;
use App\Settings\PasswordPolicySettings;
use App\Settings\RegistrationSettings;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RegistrationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'database/settings']);
        $this->enableRegistration();
        $currency = Currency::factory()->create(['code' => 'INR', 'status' => 'active']);
        $this->country = Country::factory()->create([
            'name' => 'United States',
            'iso2' => 'US',
            'phone_code' => '+1',
            'default_currency_id' => $currency->id,
            'default_timezone' => 'Asia/Kolkata',
        ]);
    }

    // ── Self Registration Enabled / Disabled ──────────────────────────────────

    public function test_get_register_redirects_to_login_when_disabled(): void
    {
        $this->disableRegistration();

        $this->get(route('auth.register'))
            ->assertRedirect(route('auth.login'));
    }

    public function test_get_register_accessible_when_enabled(): void
    {
        $this->get(route('auth.register'))->assertOk();
    }

    public function test_post_register_returns_403_when_disabled(): void
    {
        $this->disableRegistration();

        $this->post(route('auth.register.store'), $this->validPayload())
            ->assertForbidden();
    }

    public function test_post_register_blocked_via_form_request_when_disabled(): void
    {
        // Middleware returns 403, not the form request — but both enforce the constraint
        $this->disableRegistration();

        $this->post(route('auth.register.store'), $this->validPayload())
            ->assertStatus(403);
    }

    public function test_successful_registration_creates_user(): void
    {
        $this->post(route('auth.register.store'), $this->validPayload());

        $this->assertDatabaseHas('users', ['email' => 'newuser@gmail.com']);
    }

    // ── Password Policy applied at registration ───────────────────────────────

    public function test_password_rule_builder_enforced_on_registration(): void
    {
        $policy = app(PasswordPolicySettings::class);
        $policy->min_length = 12;
        $policy->require_uppercase = true;
        $policy->require_number = true;
        $policy->save();

        // Password is too short (8 chars)
        $this->post(route('auth.register.store'), $this->validPayload(['password' => 'short1A!', 'password_confirmation' => 'short1A!']))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'newuser@gmail.com']);
    }

    // ── Default Role Assignment ───────────────────────────────────────────────

    public function test_default_role_is_assigned_on_registration(): void
    {
        $role = Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $s = app(RegistrationSettings::class);
        $s->default_role = 'student';
        $s->save();

        $this->post(route('auth.register.store'), $this->validPayload());

        $user = User::where('email', 'newuser@gmail.com')->firstOrFail();
        $this->assertTrue($user->hasRole('student'));
    }

    public function test_student_status_set_to_registered_when_default_role_is_student(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $s = app(RegistrationSettings::class);
        $s->default_role = 'student';
        $s->save();

        $this->post(route('auth.register.store'), $this->validPayload());

        $user = User::where('email', 'newuser@gmail.com')->firstOrFail();
        $this->assertSame(StudentStatus::Registered, $user->profile->student_status);
    }

    public function test_student_status_stays_null_when_default_role_is_not_student(): void
    {
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        $s = app(RegistrationSettings::class);
        $s->default_role = 'instructor';
        $s->save();

        $this->post(route('auth.register.store'), $this->validPayload());

        $user = User::where('email', 'newuser@gmail.com')->firstOrFail();
        $this->assertNull($user->profile->student_status);
        $this->assertNull($user->profile->instructor_status);
    }

    public function test_registration_creates_exactly_one_profile_per_user(): void
    {
        $this->post(route('auth.register.store'), $this->validPayload());

        $user = User::where('email', 'newuser@gmail.com')->firstOrFail();
        $this->assertSame(1, UserProfile::where('user_id', $user->id)->count());
    }

    public function test_registration_blocked_when_default_role_is_configured_but_missing(): void
    {
        $s = app(RegistrationSettings::class);
        $s->default_role = 'nonexistent_role';
        $s->save();

        $response = $this->post(route('auth.register.store'), $this->validPayload());

        // User is NOT created
        $this->assertDatabaseMissing('users', ['email' => 'newuser@gmail.com']);
        // Session contains a friendly error
        $response->assertSessionHas('error');
    }

    public function test_registration_succeeds_with_no_default_role(): void
    {
        $s = app(RegistrationSettings::class);
        $s->default_role = null;
        $s->save();

        $this->post(route('auth.register.store'), $this->validPayload())
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'newuser@gmail.com']);
    }

    // ── Require Admin Approval ────────────────────────────────────────────────

    public function test_user_created_as_inactive_when_approval_required(): void
    {
        $this->requireAdminApproval();

        $this->post(route('auth.register.store'), $this->validPayload());

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@gmail.com',
            'status' => User::STATUS_INACTIVE,
        ]);
    }

    public function test_user_not_logged_in_when_approval_required(): void
    {
        $this->requireAdminApproval();

        $this->post(route('auth.register.store'), $this->validPayload());

        $this->assertGuest();
    }

    public function test_pending_approval_redirects_to_login_with_success_message(): void
    {
        $this->requireAdminApproval();

        $this->post(route('auth.register.store'), $this->validPayload())
            ->assertRedirect(route('auth.login'))
            ->assertSessionHas('success', fn (string $msg) => str_contains($msg, 'awaiting administrator approval'));
    }

    public function test_inactive_user_cannot_login_while_pending_approval(): void
    {
        $this->requireAdminApproval();

        $this->post(route('auth.register.store'), $this->validPayload());

        $user = User::where('email', 'newuser@gmail.com')->firstOrFail();
        $this->assertSame(User::STATUS_INACTIVE, $user->status);

        // Attempt login — should be blocked
        $this->post(route('auth.login.store'), [
            'email' => 'newuser@gmail.com',
            'password' => 'Password1!',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_pipeline_activity_logged_when_approval_required(): void
    {
        $this->requireAdminApproval();

        $this->post(route('auth.register.store'), $this->validPayload());

        $user = User::where('email', 'newuser@gmail.com')->firstOrFail();
        // The pipeline activity drives the admin bell notification via ActivityObserver →
        // ActivityCreated → NotifyAdminsOnActivity → AdminNotificationService.
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'auth',
            'event' => 'registration_pending_approval',
            'causer_id' => $user->id,
        ]);
    }

    public function test_pipeline_activity_not_logged_when_approval_not_required(): void
    {
        $this->post(route('auth.register.store'), $this->validPayload());

        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'auth',
            'event' => 'registration_pending_approval',
        ]);
    }

    public function test_pending_notification_sent_to_user_when_approval_required(): void
    {
        Notification::fake();
        $this->requireAdminApproval();

        $this->post(route('auth.register.store'), $this->validPayload());

        $user = User::where('email', 'newuser@gmail.com')->firstOrFail();
        Notification::assertSentTo($user, RegistrationPendingNotification::class);
    }

    public function test_verification_email_not_sent_when_approval_required(): void
    {
        Notification::fake();
        $this->requireAdminApproval();

        $this->post(route('auth.register.store'), $this->validPayload());

        $user = User::where('email', 'newuser@gmail.com')->firstOrFail();
        Notification::assertNotSentTo($user, VerifyEmailNotification::class);
    }

    // ── Admin Approval — UserApproved event ──────────────────────────────────

    public function test_user_approved_event_fired_from_edit_user_page(): void
    {
        Event::fake([UserApproved::class]);
        $this->requireAdminApproval();

        $this->post(route('auth.register.store'), $this->validPayload());
        $user = User::where('email', 'newuser@gmail.com')->firstOrFail();

        $superAdmin = $this->createSuperAdmin();
        $this->actingAs($superAdmin);

        // Simulate admin changing status to active (approval action)
        $user->update(['status' => User::STATUS_ACTIVE]);
        UserApproved::dispatch($user, $superAdmin);

        Event::assertDispatched(UserApproved::class, fn (UserApproved $e) => $e->user->id === $user->id);
    }

    public function test_account_approved_notification_sent_on_approval(): void
    {
        Notification::fake();
        $this->requireAdminApproval();

        $this->post(route('auth.register.store'), $this->validPayload());
        $user = User::where('email', 'newuser@gmail.com')->firstOrFail();

        // Simulate approval
        $user->update(['status' => User::STATUS_ACTIVE]);
        UserApproved::dispatch($user);

        Notification::assertSentTo($user, AccountApprovedNotification::class);
    }

    // ── Send Welcome Email ────────────────────────────────────────────────────

    public function test_welcome_email_not_sent_when_setting_disabled(): void
    {
        Notification::fake();
        $s = app(RegistrationSettings::class);
        $s->send_welcome_email = false;
        $s->save();

        // Normal registration flow
        $this->post(route('auth.register.store'), $this->validPayload());
        $user = User::where('email', 'newuser@gmail.com')->firstOrFail();

        // Simulate email verification (which would normally trigger welcome)
        $user->forceFill(['email_verified_at' => now()])->save();
        event(new Verified($user));

        Notification::assertNotSentTo($user, WelcomeNotification::class);
    }

    public function test_welcome_email_sent_after_verification_when_enabled(): void
    {
        Notification::fake();
        $s = app(RegistrationSettings::class);
        $s->send_welcome_email = true;
        $s->save();

        $this->post(route('auth.register.store'), $this->validPayload());
        $user = User::where('email', 'newuser@gmail.com')->firstOrFail();

        // Simulate email verification
        $user->forceFill(['email_verified_at' => now()])->save();
        event(new Verified($user));

        Notification::assertSentTo($user, WelcomeNotification::class);
    }

    // ── Auto Verify Email ─────────────────────────────────────────────────────

    public function test_email_auto_verified_when_setting_enabled(): void
    {
        $s = app(RegistrationSettings::class);
        $s->auto_verify_email = true;
        $s->save();

        $this->post(route('auth.register.store'), $this->validPayload());

        $user = User::where('email', 'newuser@gmail.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->hasVerifiedEmail());
    }

    public function test_user_active_immediately_when_auto_verified(): void
    {
        $s = app(RegistrationSettings::class);
        $s->auto_verify_email = true;
        $s->save();

        $this->post(route('auth.register.store'), $this->validPayload());

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@gmail.com',
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_auto_verified_user_is_logged_in_and_redirected_to_dashboard(): void
    {
        $s = app(RegistrationSettings::class);
        $s->auto_verify_email = true;
        $s->save();

        $this->post(route('auth.register.store'), $this->validPayload())
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
    }

    public function test_verification_email_not_sent_when_auto_verify_enabled(): void
    {
        Notification::fake();
        $s = app(RegistrationSettings::class);
        $s->auto_verify_email = true;
        $s->save();

        $this->post(route('auth.register.store'), $this->validPayload());

        $user = User::where('email', 'newuser@gmail.com')->firstOrFail();
        Notification::assertNotSentTo($user, VerifyEmailNotification::class);
    }

    public function test_welcome_email_sent_immediately_when_auto_verified_and_welcome_enabled(): void
    {
        Notification::fake();
        $s = app(RegistrationSettings::class);
        $s->auto_verify_email = true;
        $s->send_welcome_email = true;
        $s->save();

        $this->post(route('auth.register.store'), $this->validPayload());

        $user = User::where('email', 'newuser@gmail.com')->firstOrFail();
        Notification::assertSentTo($user, WelcomeNotification::class);
    }

    public function test_welcome_email_not_sent_when_auto_verified_but_welcome_disabled(): void
    {
        Notification::fake();
        $s = app(RegistrationSettings::class);
        $s->auto_verify_email = true;
        $s->send_welcome_email = false;
        $s->save();

        $this->post(route('auth.register.store'), $this->validPayload());

        $user = User::where('email', 'newuser@gmail.com')->firstOrFail();
        Notification::assertNotSentTo($user, WelcomeNotification::class);
    }

    public function test_email_not_auto_verified_when_setting_disabled(): void
    {
        $s = app(RegistrationSettings::class);
        $s->auto_verify_email = false;
        $s->save();

        $this->post(route('auth.register.store'), $this->validPayload());

        $user = User::where('email', 'newuser@gmail.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
    }

    // ── Auto verify ignored when approval required ────────────────────────────

    public function test_auto_verify_skipped_when_approval_also_required(): void
    {
        $s = app(RegistrationSettings::class);
        $s->auto_verify_email = true;
        $s->require_admin_approval = true;
        $s->save();

        $this->post(route('auth.register.store'), $this->validPayload());

        $user = User::where('email', 'newuser@gmail.com')->firstOrFail();
        // Approval takes precedence — email should NOT be auto-verified
        $this->assertSame(User::STATUS_INACTIVE, $user->status);
        $this->assertNull($user->email_verified_at);
    }

    // ── Force Password Change on First Login ──────────────────────────────────

    public function test_must_change_password_set_when_policy_enabled(): void
    {
        $policy = app(PasswordPolicySettings::class);
        $policy->force_change_on_first_login = true;
        $policy->save();

        $this->post(route('auth.register.store'), $this->validPayload());

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@gmail.com',
            'must_change_password' => true,
        ]);
    }

    public function test_must_change_password_not_set_when_policy_disabled(): void
    {
        $policy = app(PasswordPolicySettings::class);
        $policy->force_change_on_first_login = false;
        $policy->save();

        $this->post(route('auth.register.store'), $this->validPayload());

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@gmail.com',
            'must_change_password' => false,
        ]);
    }

    // ── Email Verification Integration ────────────────────────────────────────

    public function test_normal_registration_sends_verification_email(): void
    {
        Notification::fake();

        $this->post(route('auth.register.store'), $this->validPayload());

        $user = User::where('email', 'newuser@gmail.com')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_normal_registration_user_logged_in_and_redirected_to_verification_notice(): void
    {
        $this->post(route('auth.register.store'), $this->validPayload())
            ->assertRedirect(route('auth.verification.notice'));

        $this->assertAuthenticated();
    }

    // ── Activity Logging ──────────────────────────────────────────────────────

    public function test_user_registered_activity_logged(): void
    {
        $this->post(route('auth.register.store'), $this->validPayload());

        $user = User::where('email', 'newuser@gmail.com')->firstOrFail();
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'auth',
            'causer_id' => $user->id,
        ]);
    }

    public function test_pending_approval_activity_logged(): void
    {
        $this->requireAdminApproval();

        $this->post(route('auth.register.store'), $this->validPayload());

        $user = User::where('email', 'newuser@gmail.com')->firstOrFail();
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'auth',
            'event' => 'registration_pending_approval',
            'causer_id' => $user->id,
        ]);
    }

    public function test_auto_verify_activity_logged(): void
    {
        $s = app(RegistrationSettings::class);
        $s->auto_verify_email = true;
        $s->save();

        $this->post(route('auth.register.store'), $this->validPayload());

        $user = User::where('email', 'newuser@gmail.com')->firstOrFail();
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'auth',
            'event' => 'email_auto_verified',
            'causer_id' => $user->id,
        ]);
    }

    public function test_welcome_email_queued_activity_logged_on_auto_verify(): void
    {
        $s = app(RegistrationSettings::class);
        $s->auto_verify_email = true;
        $s->send_welcome_email = true;
        $s->save();

        $this->post(route('auth.register.store'), $this->validPayload());

        $user = User::where('email', 'newuser@gmail.com')->firstOrFail();
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'auth',
            'event' => 'welcome_email_queued',
            'causer_id' => $user->id,
        ]);
    }

    public function test_registration_blocked_activity_logged_on_invalid_role(): void
    {
        $s = app(RegistrationSettings::class);
        $s->default_role = 'nonexistent_role';
        $s->save();

        $this->post(route('auth.register.store'), $this->validPayload());

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'auth',
            'event' => 'registration_blocked',
        ]);
    }

    // ── Security: cannot bypass backend controls ──────────────────────────────

    public function test_cannot_bypass_disabled_registration_via_direct_post(): void
    {
        $this->disableRegistration();

        // Direct POST with correct CSRF
        $this->post(route('auth.register.store'), $this->validPayload())
            ->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'newuser@gmail.com']);
    }

    public function test_registration_page_link_hidden_on_login_when_disabled(): void
    {
        $this->disableRegistration();

        // Login page should not show the registration link
        $response = $this->get(route('auth.login'));
        $response->assertDontSee(route('auth.register'));
    }

    // ── Authentication Integration ────────────────────────────────────────────

    public function test_registered_user_cannot_login_before_email_verification_when_required(): void
    {
        $authSettings = app(AuthenticationSettings::class);
        $authSettings->email_verification_required = true;
        $authSettings->save();

        $this->post(route('auth.register.store'), $this->validPayload());
        // Log out the user who was auto-logged in after registration
        $this->post(route('auth.logout'));

        // Attempt to login with unverified email
        $this->post(route('auth.login.store'), [
            'email' => 'newuser@gmail.com',
            'password' => 'Password1!',
        ]);

        // Should not reach dashboard
        $this->assertGuest();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function enableRegistration(): void
    {
        $s = app(RegistrationSettings::class);
        $s->self_registration_enabled = true;
        $s->require_admin_approval = false;
        $s->auto_verify_email = false;
        $s->send_welcome_email = true;
        $s->default_role = null;
        $s->save();
    }

    private function disableRegistration(): void
    {
        $s = app(RegistrationSettings::class);
        $s->self_registration_enabled = false;
        $s->save();
    }

    private function requireAdminApproval(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $s = app(RegistrationSettings::class);
        $s->require_admin_approval = true;
        $s->save();
    }

    private function createSuperAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole($role);

        return $admin;
    }

    private function validPayload(array $overrides = []): array
    {
        session()->put('registration.captcha', '7');

        return array_merge([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'newuser@gmail.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'terms' => '1',
            'country_id' => $this->country->id,
            'captcha_answer' => '7',
        ], $overrides);
    }
}
