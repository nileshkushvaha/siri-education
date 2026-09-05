<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\StudentStatus;
use App\Events\Auth\UserRegistered;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Settings\AuthenticationSettings;
use App\Settings\PasswordPolicySettings;
use App\Settings\RegistrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GoogleStudentRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const string SUBJECT = '108374000000000000002';

    private Country $country;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/settings']);
        Notification::fake();

        config()->set('services.google.client_id', 'test-client-id');
        config()->set('services.google.client_secret', 'test-client-secret');

        foreach (['student', 'instructor', 'manager', 'super_admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $auth = app(AuthenticationSettings::class);
        $auth->login_enabled = true;
        $auth->social_login_enabled = true;
        $auth->save();

        $policy = app(PasswordPolicySettings::class);
        $policy->force_change_on_first_login = false;
        $policy->save();

        $this->registrationSettings(selfRegistration: true, requireApproval: false, defaultRole: 'instructor');

        $currency = Currency::factory()->create(['code' => 'INR', 'status' => 'active']);
        $this->country = Country::factory()->create([
            'name' => 'India', 'iso2' => 'IN', 'phone_code' => '+91',
            'default_currency_id' => $currency->id, 'default_timezone' => 'Asia/Kolkata',
        ]);
    }

    public function test_unknown_google_email_becomes_an_active_student_and_is_signed_in(): void
    {
        Event::fake([UserRegistered::class]);
        $this->fakeGoogle('new.student@gmail.com', givenName: 'Asha', familyName: 'Rao', locale: 'en-IN');

        $this->hitCallback()->assertRedirect(route('dashboard'));

        $user = User::where('email', 'new.student@gmail.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);

        $this->assertSame('Asha', $user->first_name);
        $this->assertSame('Rao', $user->last_name);
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->password_changed_at);
        $this->assertSame(self::SUBJECT, $user->google_subject);
        $this->assertNotNull($user->google_linked_at);

        // Always student — never the configured default role (set to instructor above).
        $this->assertSame(['student'], $user->roles->pluck('name')->all());
        $this->assertSame(StudentStatus::Active, $user->profile->student_status);
        $this->assertNull($user->profile->country_id);
        $this->assertNull($user->profile->phone_e164);

        Event::assertDispatched(UserRegistered::class, fn (UserRegistered $e) => $e->user->is($user));
        $this->assertDatabaseHas('activity_log', ['event' => 'google_student_registered', 'subject_id' => $user->id]);
        $this->assertDatabaseHas('activity_log', ['event' => 'google_account_linked', 'subject_id' => $user->id]);
        $this->assertDatabaseHas('login_histories', ['user_id' => $user->id, 'login_method' => 'google', 'status' => 'success']);

        // Password step comes first, and the Google locale hint survives for the profile prefill.
        $this->get(route('dashboard'))->assertRedirect(route('auth.password.change-required'));
        $this->assertSame('en-IN', session('google_locale_hint'));
    }

    public function test_display_name_is_split_when_google_sends_no_given_and_family_names(): void
    {
        $this->fakeGoogle('mono@gmail.com', name: 'Priya Sharma Iyer');

        $this->hitCallback();

        $user = User::where('email', 'mono@gmail.com')->firstOrFail();
        $this->assertSame('Priya', $user->first_name);
        $this->assertSame('Sharma Iyer', $user->last_name);
    }

    public function test_self_registration_disabled_keeps_unknown_emails_out(): void
    {
        $this->registrationSettings(selfRegistration: false, requireApproval: false, defaultRole: 'student');
        $this->fakeGoogle('closed@gmail.com');

        $this->hitCallback()
            ->assertRedirect(route('auth.login'))
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'registrations are currently closed'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'closed@gmail.com']);
    }

    public function test_approval_required_creates_an_inactive_student_and_does_not_sign_in(): void
    {
        $this->registrationSettings(selfRegistration: true, requireApproval: true, defaultRole: 'student');
        $this->fakeGoogle('approve.me@gmail.com');

        $this->hitCallback()
            ->assertRedirect(route('auth.login'))
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'awaiting administrator approval'));

        $this->assertGuest();

        $user = User::where('email', 'approve.me@gmail.com')->firstOrFail();
        $this->assertSame(User::STATUS_INACTIVE, $user->status);
        $this->assertSame(self::SUBJECT, $user->google_subject);
        $this->assertSame(['student'], $user->roles->pluck('name')->all());
        $this->assertSame(StudentStatus::Registered, $user->profile->student_status);
    }

    public function test_google_registered_student_is_blocked_from_booking_until_profile_is_complete(): void
    {
        $this->fakeGoogle('booker@gmail.com');
        $this->hitCallback();
        $this->post(route('auth.password.change-required.store'), [
            'password' => 'MyOwnPassw0rd!', 'password_confirmation' => 'MyOwnPassw0rd!',
        ]);

        $this->get(route('dashboard'))->assertOk()->assertSee('Complete your profile to start booking');
        $this->get(route('booking.create'))->assertRedirect(route('account.complete-profile'));
        $this->assertSame(url(route('booking.create')), session('url.intended'));

        $this->get(route('account.complete-profile'))
            ->assertOk()
            ->assertSee('Complete your profile')
            ->assertSee('India');

        $this->post(route('account.complete-profile.store'), [
            'first_name' => 'Booker', 'last_name' => 'Test',
            'country_id' => $this->country->id,
            'phone_country_iso2' => 'IN', 'phone' => '9876543210',
            'terms' => '1',
        ])->assertRedirect(route('booking.create'));

        $user = User::where('email', 'booker@gmail.com')->firstOrFail();
        $this->assertSame($this->country->id, $user->profile->country_id);
        $this->assertSame('+919876543210', $user->profile->phone_e164);
        $this->assertSame('+91', $user->profile->phone_dial_code);
        $this->assertSame('Asia/Kolkata', $user->profile->timezone);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertNotNull($user->privacy_accepted_at);
        $this->assertDatabaseHas('activity_log', ['event' => 'profile_completed', 'subject_id' => $user->id]);

        $this->get(route('booking.create'))->assertOk();
        $this->get(route('account.complete-profile'))->assertRedirect();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Complete your profile to start booking');
    }

    public function test_complete_profile_validation_requires_phone_supported_country_and_terms(): void
    {
        $this->fakeGoogle('strict@gmail.com');
        $this->hitCallback();
        $this->post(route('auth.password.change-required.store'), [
            'password' => 'MyOwnPassw0rd!', 'password_confirmation' => 'MyOwnPassw0rd!',
        ]);

        $unsupported = Country::factory()->create(['name' => 'Nowhere', 'iso2' => 'XX', 'phone_code' => '+999', 'default_currency_id' => null]);

        $this->from(route('account.complete-profile'))
            ->post(route('account.complete-profile.store'), [
                'first_name' => 'Strict',
                'country_id' => $unsupported->id,
                'phone_country_iso2' => 'IN',
                'phone' => '',
                'terms' => '',
            ])
            ->assertRedirect(route('account.complete-profile'))
            ->assertSessionHasErrors(['country_id', 'phone', 'terms']);

        $this->get(route('booking.create'))->assertRedirect(route('account.complete-profile'));
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function registrationSettings(bool $selfRegistration, bool $requireApproval, string $defaultRole): void
    {
        $reg = app(RegistrationSettings::class);
        $reg->self_registration_enabled = $selfRegistration;
        $reg->require_admin_approval = $requireApproval;
        $reg->default_role = $defaultRole;
        $reg->auto_verify_email = false;
        $reg->save();
    }

    private function fakeGoogle(string $email, ?string $name = null, ?string $givenName = null, ?string $familyName = null, ?string $locale = null): void
    {
        $raw = array_filter([
            'sub' => self::SUBJECT, 'email' => $email, 'email_verified' => true,
            'name' => $name ?? trim(($givenName ?? '').' '.($familyName ?? '')),
            'given_name' => $givenName, 'family_name' => $familyName, 'locale' => $locale,
        ], fn ($v) => $v !== null && $v !== '');

        $googleUser = (new SocialiteUser)->setRaw($raw)->map([
            'id' => self::SUBJECT, 'email' => $email, 'name' => $raw['name'] ?? null, 'nickname' => null, 'avatar' => null,
        ]);

        Socialite::shouldReceive('driver->user')->andReturn($googleUser);
    }

    private function hitCallback(): TestResponse
    {
        return $this->get(route('auth.google.callback', ['code' => 'fake-code', 'state' => 'fake-state']));
    }
}
