<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\LoginResult;
use App\Jobs\Auth\ImportGoogleAvatarJob;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\Profile\ProfileCompletionService;
use App\Settings\AuthenticationSettings;
use App\Settings\PasswordPolicySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\Downloaders\Downloader;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GoogleActivationTest extends TestCase
{
    use RefreshDatabase;

    private const string SUBJECT = '108374000000000000001';

    private const string ALREADY_ACTIVATED = 'Your account has already been activated. Please sign in with your email and password. Forgot it? Use "Forgot password" below.';

    private const string OAUTH_FAILED = 'Sign-in with Google was interrupted before it completed. Please try again from this page.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/settings']);
        Notification::fake();

        config()->set('services.google.client_id', 'test-client-id');
        config()->set('services.google.client_secret', 'test-client-secret');
        config()->set('services.google.redirect', '/auth/google/callback');

        foreach (['student', 'instructor', 'manager', 'super_admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->enableGoogle(true);

        // Google activation must force the password step even when the
        // generic first-login toggle is OFF.
        $policy = app(PasswordPolicySettings::class);
        $policy->force_change_on_first_login = false;
        $policy->save();
    }

    // ── Happy path ───────────────────────────────────────────────────

    public function test_first_time_student_is_linked_signed_in_and_forced_to_create_a_password(): void
    {
        $user = $this->preCreatedUser('student');
        $this->fakeGoogle(email: $user->email);

        $this->hitCallback()
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);

        $user->refresh();
        $this->assertSame(self::SUBJECT, $user->google_subject);
        $this->assertSame($user->email, $user->google_email);
        $this->assertNotNull($user->google_linked_at);
        $this->assertTrue($user->must_change_password);
        $this->assertNotNull($user->last_login_at);

        // Nothing but the set-password screen is reachable.
        $this->get(route('dashboard'))->assertRedirect(route('auth.password.change-required'));

        $this->assertDatabaseHas('login_histories', ['user_id' => $user->id, 'login_method' => 'google', 'status' => 'success']);
        $this->assertDatabaseHas('activity_log', ['event' => 'google_account_linked', 'subject_id' => $user->id]);
        $this->assertSame(1, Activity::query()->where('event', 'google_account_linked')->count());
    }

    public function test_creating_the_password_activates_the_account_and_password_login_works_afterwards(): void
    {
        $user = $this->preCreatedUser('student');
        $this->fakeGoogle(email: $user->email);
        $this->hitCallback();

        $this->get(route('auth.password.change-required'))
            ->assertOk()
            ->assertSee('Create your password')
            ->assertSee('verified with Google');

        $this->post(route('auth.password.change-required.store'), [
            'password' => 'MyOwnPassw0rd!',
            'password_confirmation' => 'MyOwnPassw0rd!',
        ])->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue(Hash::check('MyOwnPassw0rd!', $user->password));
        $this->assertDatabaseHas('activity_log', ['event' => 'account_activated', 'subject_id' => $user->id]);

        $this->get(route('dashboard'))->assertOk();

        // Log out, then sign in the ordinary way.
        $this->post(route('auth.logout'));
        $this->assertGuest();

        $this->post(route('auth.login.store'), ['email' => $user->email, 'password' => 'MyOwnPassw0rd!'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_google_is_refused_once_the_account_is_activated(): void
    {
        $user = $this->preCreatedUser('student');
        $this->fakeGoogle(email: $user->email);
        $this->hitCallback();
        $this->post(route('auth.password.change-required.store'), [
            'password' => 'MyOwnPassw0rd!',
            'password_confirmation' => 'MyOwnPassw0rd!',
        ]);
        $this->post(route('auth.logout'));

        $this->fakeGoogle(email: $user->email);
        $this->hitCallback()
            ->assertRedirect(route('auth.login'))
            ->assertSessionHas('error', self::ALREADY_ACTIVATED);

        $this->assertGuest();
        $this->assertDatabaseHas('activity_log', ['event' => 'google_login_rejected', 'subject_id' => $user->id]);
    }

    public function test_user_who_linked_but_abandoned_the_password_step_can_return_via_google(): void
    {
        $user = $this->preCreatedUser('student');
        $this->fakeGoogle(email: $user->email);
        $this->hitCallback();
        $this->post(route('auth.logout'));

        $this->fakeGoogle(email: $user->email);
        $this->hitCallback()->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, Activity::query()->where('event', 'google_account_linked')->count());
    }

    public function test_existing_user_with_own_password_is_linked_and_not_forced_to_reset(): void
    {
        $user = $this->preCreatedUser('instructor', ['password_changed_at' => now()->subMonth()]);
        $this->fakeGoogle(email: $user->email);

        $this->hitCallback()->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $user->refresh();
        $this->assertSame(self::SUBJECT, $user->google_subject);
        $this->assertFalse($user->must_change_password);
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_pending_verification_user_with_matching_email_is_verified_and_activated(): void
    {
        $user = $this->preCreatedUser('student', ['status' => User::STATUS_PENDING, 'email_verified_at' => null]);
        $this->fakeGoogle(email: $user->email);

        $this->hitCallback()->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertNotNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_google_roles_and_permissions_are_untouched(): void
    {
        $user = $this->preCreatedUser('student');
        $this->fakeGoogle(email: $user->email);
        $this->hitCallback();

        $this->assertSame(['student'], $user->fresh()->roles->pluck('name')->all());
    }

    // ── Denials ──────────────────────────────────────────────────────

    public function test_unknown_google_email_is_denied_and_no_user_is_created(): void
    {
        $this->fakeGoogle(email: 'random.person@gmail.com');

        $this->hitCallback()
            ->assertRedirect(route('auth.login'))
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'No student or instructor account is registered for random.person@gmail.com'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'random.person@gmail.com']);
        $this->assertDatabaseHas('activity_log', ['event' => 'google_login_rejected']);
    }

    public function test_unverified_google_email_is_denied(): void
    {
        $user = $this->preCreatedUser('student');
        $this->fakeGoogle(email: $user->email, verified: false);

        $this->hitCallback()->assertSessionHas('error', fn (string $m) => str_contains($m, 'could not confirm the email address'));

        $this->assertGuest();
        $this->assertNull($user->fresh()->google_subject);
    }

    public function test_admin_portal_roles_are_denied(): void
    {
        $manager = $this->preCreatedUser('manager');
        $this->fakeGoogle(email: $manager->email);

        $this->hitCallback()
            ->assertRedirect(route('auth.login'))
            ->assertSessionHas('error', fn (string $m) => str_contains($m, '/admin/login'));

        $this->assertGuest();
        $this->assertNull($manager->fresh()->google_subject);
    }

    public function test_google_identity_already_linked_to_another_user_is_denied(): void
    {
        $owner = $this->preCreatedUser('student', ['google_subject' => self::SUBJECT, 'google_linked_at' => now(), 'password_changed_at' => now()]);
        $victim = $this->preCreatedUser('student');

        // Same subject comes back claiming the victim's email: the subject
        // wins the lookup, and the owner is already activated → refused.
        $this->fakeGoogle(email: $victim->email);
        $this->hitCallback()->assertSessionHas('error', self::ALREADY_ACTIVATED);

        $this->assertGuest();
        $this->assertNull($victim->fresh()->google_subject);
        $this->assertSame(self::SUBJECT, $owner->fresh()->google_subject);
    }

    public function test_user_already_linked_to_a_different_google_subject_is_denied(): void
    {
        $user = $this->preCreatedUser('student', ['google_subject' => 'other-subject', 'google_linked_at' => now()]);
        $this->fakeGoogle(email: $user->email);

        $this->hitCallback()->assertSessionHas('error', fn (string $m) => str_contains($m, 'already linked to a different Google account'));

        $this->assertGuest();
        $this->assertSame('other-subject', $user->fresh()->google_subject);
    }

    #[DataProvider('unavailableStatuses')]
    public function test_unavailable_accounts_are_denied_even_after_google_verifies_them(string $status, string $expectedMessage): void
    {
        $user = $this->preCreatedUser('student', ['status' => $status]);
        $this->fakeGoogle(email: $user->email);

        $this->hitCallback()->assertSessionHas('error', $expectedMessage);

        $this->assertGuest();
        $this->assertSame($status, $user->fresh()->status);
        $this->assertDatabaseMissing('login_histories', ['user_id' => $user->id, 'status' => 'success']);
        $this->assertDatabaseHas('activity_log', ['event' => 'google_login_rejected', 'subject_id' => $user->id]);
    }

    public static function unavailableStatuses(): array
    {
        return [
            'inactive' => [User::STATUS_INACTIVE, LoginResult::AccountInactive->message()],
            'blocked' => [User::STATUS_BLOCKED, LoginResult::AccountBlocked->message()],
            'suspended' => [User::STATUS_SUSPENDED, LoginResult::AccountBlocked->message()],
        ];
    }

    public function test_feature_toggle_off_hides_the_button_and_blocks_both_routes(): void
    {
        $this->enableGoogle(false);
        $user = $this->preCreatedUser('student');
        $this->fakeGoogle(email: $user->email);

        $this->get(route('auth.login'))->assertOk()->assertDontSee('Continue with Google');
        $this->get(route('auth.google.redirect'))->assertRedirect(route('auth.login'))->assertSessionHas('error');
        $this->hitCallback()->assertRedirect(route('auth.login'))->assertSessionHas('error');

        $this->assertGuest();
        $this->assertNull($user->fresh()->google_subject);
    }

    public function test_login_page_shows_the_google_button_when_enabled(): void
    {
        $this->get(route('auth.login'))->assertOk()->assertSee('Continue with Google');
    }

    public function test_redirect_route_sends_the_visitor_to_google_with_identity_scopes_only(): void
    {
        $response = $this->get(route('auth.google.redirect'));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth', $location);
        $this->assertStringContainsString('state=', $location);
        $this->assertStringContainsString('scope=openid', $location);
        $this->assertStringNotContainsString('drive', $location);
    }

    public function test_consent_denied_at_google_is_handled(): void
    {
        $this->get(route('auth.google.callback', ['error' => 'access_denied']))
            ->assertRedirect(route('auth.login'))
            ->assertSessionHas('error', self::OAUTH_FAILED);

        $this->assertGuest();
    }

    public function test_invalid_oauth_state_is_denied(): void
    {
        Socialite::shouldReceive('driver->user')->andThrow(new InvalidStateException);

        $this->hitCallback()
            ->assertRedirect(route('auth.login'))
            ->assertSessionHas('error', self::OAUTH_FAILED);

        $this->assertGuest();
    }

    // ── Avatar import ────────────────────────────────────────────────

    public function test_google_picture_is_imported_in_the_background_when_the_user_has_no_avatar(): void
    {
        Queue::fake([ImportGoogleAvatarJob::class]);
        $user = $this->preCreatedUser('student');
        $this->fakeGoogle(email: $user->email, picture: 'https://lh3.googleusercontent.com/a/ACg8ocJ=s96-c');

        $this->hitCallback()->assertRedirect(route('dashboard'));

        Queue::assertPushed(ImportGoogleAvatarJob::class, fn (ImportGoogleAvatarJob $job) => $job->userId === $user->id
            && $job->pictureUrl === 'https://lh3.googleusercontent.com/a/ACg8ocJ=s96-c');
    }

    public function test_no_picture_or_non_google_picture_host_queues_nothing(): void
    {
        Queue::fake([ImportGoogleAvatarJob::class]);
        $user = $this->preCreatedUser('student');
        $this->fakeGoogle(email: $user->email, picture: 'https://evil.example.com/steal.png');

        $this->hitCallback()->assertRedirect(route('dashboard'));

        Queue::assertNotPushed(ImportGoogleAvatarJob::class);
    }

    public function test_avatar_job_stores_the_picture_once_and_never_replaces_an_existing_avatar(): void
    {
        Storage::fake('public');
        config()->set('media-library.media_downloader', FakeGoogleImageDownloader::class);
        $user = $this->preCreatedUser('student');

        (new ImportGoogleAvatarJob($user->id, 'https://lh3.googleusercontent.com/a/ACg8ocJ=s96-c'))
            ->handle(app(AuditTrailService::class), app(ProfileCompletionService::class));

        $profile = $user->profile->fresh();
        $this->assertTrue($profile->hasMedia('avatar'));
        $firstMediaId = $profile->getFirstMedia('avatar')->id;
        $this->assertDatabaseHas('activity_log', ['event' => 'avatar_changed', 'subject_id' => $user->id]);

        // Second run (e.g. a later Google visit) must not touch the existing photo.
        (new ImportGoogleAvatarJob($user->id, 'https://lh3.googleusercontent.com/a/OTHER=s96-c'))
            ->handle(app(AuditTrailService::class), app(ProfileCompletionService::class));

        $this->assertSame($firstMediaId, $user->profile->fresh()->getFirstMedia('avatar')->id);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function enableGoogle(bool $enabled): void
    {
        $settings = app(AuthenticationSettings::class);
        $settings->login_enabled = true;
        $settings->social_login_enabled = $enabled;
        $settings->save();
    }

    /** @param array<string, mixed> $attributes */
    private function preCreatedUser(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'password' => Hash::make('Admin-Generated-Pass-1!'),
            'password_changed_at' => null,
            'must_change_password' => false,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ], $attributes));

        $user->assignRole($role);

        return $user;
    }

    private function fakeGoogle(string $email, bool $verified = true, string $subject = self::SUBJECT, ?string $picture = null): void
    {
        $googleUser = (new SocialiteUser)
            ->setRaw(['sub' => $subject, 'email' => $email, 'email_verified' => $verified, 'name' => 'Test Person', 'picture' => $picture])
            ->map(['id' => $subject, 'email' => $email, 'name' => 'Test Person', 'nickname' => null, 'avatar' => $picture]);

        Socialite::shouldReceive('driver->user')->andReturn($googleUser);
    }

    private function hitCallback(): TestResponse
    {
        return $this->get(route('auth.google.callback', ['code' => 'fake-code', 'state' => 'fake-state']));
    }
}

/** Stands in for Spatie's HTTP downloader: hands back a real 1x1 PNG without touching the network. */
final class FakeGoogleImageDownloader implements Downloader
{
    public function getTempFile(string $url): string
    {
        $path = tempnam(sys_get_temp_dir(), 'google-avatar');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        return $path;
    }
}
