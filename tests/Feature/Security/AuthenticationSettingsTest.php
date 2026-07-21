<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Filament\Pages\Security\AuthenticationPage;
use App\Models\User;
use App\Settings\AuthenticationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthenticationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $regularUser;

    private User $securityUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/settings']);

        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $managerRole = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $permission = Permission::firstOrCreate(['name' => 'security.authentication.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'security.authentication.update', 'guard_name' => 'web']);

        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $this->superAdmin->assignRole($superAdminRole);

        $this->regularUser = User::factory()->create(['status' => 'active']);

        // Admin Portal access requires portal membership (PortalResolver) in
        // addition to the specific security permission.
        $this->securityUser = User::factory()->create(['status' => 'active']);
        $this->securityUser->assignRole($managerRole);
        $this->securityUser->givePermissionTo($permission);
    }

    // ── Access control ─────────────────────────────────────────────────────

    public function test_super_admin_can_access_authentication_page(): void
    {
        $this->actingAs($this->superAdmin)
            ->get('/admin/security/authentication')
            ->assertOk();
    }

    public function test_user_with_permission_can_access_authentication_page(): void
    {
        $this->actingAs($this->securityUser)
            ->get('/admin/security/authentication')
            ->assertOk();
    }

    public function test_regular_user_cannot_access_authentication_page(): void
    {
        $this->actingAs($this->regularUser)
            ->get('/admin/security/authentication')
            ->assertForbidden();
    }

    // ── Navigation registration ─────────────────────────────────────────────

    public function test_navigation_is_registered_for_super_admin(): void
    {
        $this->actingAs($this->superAdmin);

        $this->assertTrue(AuthenticationPage::shouldRegisterNavigation());
    }

    public function test_navigation_is_not_registered_for_regular_user(): void
    {
        $this->actingAs($this->regularUser);

        $this->assertFalse(AuthenticationPage::shouldRegisterNavigation());
    }

    // ── Default values ──────────────────────────────────────────────────────

    public function test_default_values_are_seeded_correctly(): void
    {
        $settings = app(AuthenticationSettings::class);

        $this->assertTrue($settings->login_enabled);
        $this->assertTrue($settings->remember_me_enabled);
        $this->assertTrue($settings->email_verification_required);
        $this->assertSame('email', $settings->default_login_method);
        $this->assertFalse($settings->two_factor_enabled);
        $this->assertFalse($settings->passkeys_enabled);
        $this->assertFalse($settings->social_login_enabled);
        $this->assertFalse($settings->ldap_enabled);
        $this->assertFalse($settings->saml_enabled);
        $this->assertFalse($settings->azure_ad_enabled);
    }

    // ── Settings persistence ────────────────────────────────────────────────

    public function test_page_mounts_with_current_settings(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(AuthenticationPage::class)
            ->assertSet('data.login_enabled', true)
            ->assertSet('data.remember_me_enabled', true)
            ->assertSet('data.default_login_method', 'email');
    }

    public function test_save_persists_settings(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(AuthenticationPage::class)
            ->set('data.login_enabled', false)
            ->set('data.remember_me_enabled', false)
            ->set('data.email_verification_required', false)
            ->set('data.default_login_method', 'email')
            ->call('save');

        $settings = app()->make(AuthenticationSettings::class)->refresh();

        $this->assertFalse($settings->login_enabled);
        $this->assertFalse($settings->remember_me_enabled);
        $this->assertFalse($settings->email_verification_required);
    }

    public function test_save_shows_success_notification(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(AuthenticationPage::class)
            ->set('data.login_enabled', true)
            ->call('save')
            ->assertNotified('Authentication settings saved');
    }

    // ── Validation ──────────────────────────────────────────────────────────

    public function test_default_login_method_is_required(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(AuthenticationPage::class)
            ->set('data.default_login_method', '')
            ->call('save')
            ->assertHasErrors(['data.default_login_method']);
    }

    // ── Activity log ────────────────────────────────────────────────────────

    public function test_save_creates_activity_log_entry(): void
    {
        $this->actingAs($this->superAdmin);

        // login_enabled already defaults to true — flip it to a genuinely
        // different value so this is a real change, not a no-op (Phase
        // 24S: no-op saves correctly create no audit event).
        Livewire::test(AuthenticationPage::class)
            ->set('data.login_enabled', false)
            ->call('save');

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'settings_updated',
        ]);
    }

    public function test_save_logs_changed_fields_diff(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(AuthenticationPage::class)
            ->set('data.login_enabled', false)
            ->set('data.remember_me_enabled', false)
            ->call('save');

        $log = Activity::where('log_name', 'security')
            ->where('event', 'settings_updated')
            ->latest()
            ->first();

        $this->assertNotNull($log);
        $changed = $log->properties['changed'] ?? [];
        $this->assertArrayHasKey('login_enabled', $changed);
        $this->assertTrue($changed['login_enabled']['from']);
        $this->assertFalse($changed['login_enabled']['to']);
    }

    // ── login_enabled enforcement ───────────────────────────────────────────

    public function test_login_post_blocked_when_login_disabled(): void
    {
        $settings = app(AuthenticationSettings::class);
        $settings->login_enabled = false;
        $settings->save();

        $this->post(route('auth.login.store'), [
            'email' => 'user@example.com',
            'password' => 'password',
        ])->assertRedirect()->assertSessionHasErrors('email');
    }

    public function test_login_get_accessible_when_login_disabled(): void
    {
        $settings = app(AuthenticationSettings::class);
        $settings->login_enabled = false;
        $settings->save();

        $this->get(route('auth.login'))->assertOk();
    }

    public function test_login_post_succeeds_when_login_enabled(): void
    {
        $settings = app(AuthenticationSettings::class);
        $settings->login_enabled = true;
        $settings->email_verification_required = false;
        $settings->remember_me_enabled = false;
        $settings->save();

        $user = User::factory()->create([
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));
    }

    // ── remember_me_enabled enforcement ────────────────────────────────────

    public function test_remember_cookie_absent_when_remember_me_disabled(): void
    {
        $settings = app(AuthenticationSettings::class);
        $settings->login_enabled = true;
        $settings->remember_me_enabled = false;
        $settings->email_verification_required = false;
        $settings->save();

        $user = User::factory()->create([
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $response = $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ]);

        // When remember_me is disabled, no remember_web_* cookie should be set
        $cookies = collect($response->headers->getCookies())
            ->filter(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));

        $this->assertCount(0, $cookies);
    }

    public function test_remember_cookie_present_when_remember_me_enabled(): void
    {
        $settings = app(AuthenticationSettings::class);
        $settings->login_enabled = true;
        $settings->remember_me_enabled = true;
        $settings->email_verification_required = false;
        $settings->save();

        $user = User::factory()->create([
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $response = $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ]);

        $cookies = collect($response->headers->getCookies())
            ->filter(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));

        $this->assertGreaterThan(0, $cookies->count());
    }

    // ── email_verification_required enforcement ─────────────────────────────

    public function test_unverified_user_blocked_when_verification_required(): void
    {
        $settings = app(AuthenticationSettings::class);
        $settings->login_enabled = true;
        $settings->remember_me_enabled = false;
        $settings->email_verification_required = true;
        $settings->save();

        $user = User::factory()->create([
            'status' => 'active',
            'email_verified_at' => null,
        ]);

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHas('unverified');
    }

    public function test_unverified_user_can_login_when_verification_not_required(): void
    {
        $settings = app(AuthenticationSettings::class);
        $settings->login_enabled = true;
        $settings->remember_me_enabled = false;
        $settings->email_verification_required = false;
        $settings->save();

        $user = User::factory()->create([
            'status' => 'active',
            'email_verified_at' => null,
        ]);

        $this->post(route('auth.login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_unverified_user_can_access_dashboard_when_verification_not_required(): void
    {
        $settings = app(AuthenticationSettings::class);
        $settings->email_verification_required = false;
        $settings->save();

        $user = User::factory()->create([
            'status' => 'active',
            'email_verified_at' => null,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_unverified_user_redirected_from_dashboard_when_verification_required(): void
    {
        $settings = app(AuthenticationSettings::class);
        $settings->email_verification_required = true;
        $settings->save();

        $user = User::factory()->create([
            'status' => 'active',
            'email_verified_at' => null,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('auth.verification.notice'));
    }

    // ── Update permission enforcement ───────────────────────────────────────

    public function test_user_with_only_view_permission_cannot_save(): void
    {
        $viewOnlyUser = User::factory()->create(['status' => 'active']);
        $viewOnlyUser->givePermissionTo(
            Permission::firstOrCreate(['name' => 'security.authentication.view', 'guard_name' => 'web'])
        );

        $this->actingAs($viewOnlyUser);

        Livewire::test(AuthenticationPage::class)
            ->call('save')
            ->assertForbidden();
    }

    public function test_user_with_update_permission_can_save(): void
    {
        $updateUser = User::factory()->create(['status' => 'active']);
        $updateUser->givePermissionTo([
            Permission::firstOrCreate(['name' => 'security.authentication.view',  'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'security.authentication.update', 'guard_name' => 'web']),
        ]);

        $this->actingAs($updateUser);

        Livewire::test(AuthenticationPage::class)
            ->set('data.login_enabled', true)
            ->call('save')
            ->assertHasNoErrors();
    }
}
