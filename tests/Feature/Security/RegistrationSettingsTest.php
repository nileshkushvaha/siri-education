<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Filament\Pages\Security\RegistrationPage;
use App\Models\Activity;
use App\Models\User;
use App\Settings\RegistrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RegistrationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/settings']);

        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'security.registration.view',   'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'security.registration.update', 'guard_name' => 'web']);

        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $this->superAdmin->assignRole($superAdminRole);

        $this->regularUser = User::factory()->create(['status' => 'active']);
    }

    // ── Access control ─────────────────────────────────────────────────────

    public function test_super_admin_can_access_registration_page(): void
    {
        $this->actingAs($this->superAdmin)
            ->get('/admin/security/registration')
            ->assertOk();
    }

    public function test_regular_user_cannot_access_registration_page(): void
    {
        $this->actingAs($this->regularUser)
            ->get('/admin/security/registration')
            ->assertForbidden();
    }

    // ── Default values ──────────────────────────────────────────────────────

    public function test_default_values_are_seeded_correctly(): void
    {
        $settings = app(RegistrationSettings::class);

        $this->assertFalse($settings->self_registration_enabled);
        $this->assertNull($settings->default_role);
        $this->assertFalse($settings->require_admin_approval);
        $this->assertTrue($settings->send_welcome_email);
        $this->assertFalse($settings->auto_verify_email);
        $this->assertFalse($settings->invitation_only);
        $this->assertFalse($settings->domain_restriction_enabled);
    }

    // ── Settings persistence ────────────────────────────────────────────────

    public function test_save_persists_settings(): void
    {
        $this->actingAs($this->superAdmin);

        // Create a role so the Select can find it
        $role = Role::firstOrCreate(['name' => 'editor', 'guard_name' => 'web']);

        Livewire::test(RegistrationPage::class)
            ->set('data.self_registration_enabled', true)
            ->set('data.default_role', 'editor')
            ->set('data.require_admin_approval', true)
            ->set('data.send_welcome_email', false)
            ->set('data.auto_verify_email', true)
            ->call('save');

        $settings = app()->make(RegistrationSettings::class)->refresh();

        $this->assertTrue($settings->self_registration_enabled);
        $this->assertSame('editor', $settings->default_role);
        $this->assertTrue($settings->require_admin_approval);
        $this->assertFalse($settings->send_welcome_email);
        $this->assertTrue($settings->auto_verify_email);
    }

    public function test_save_shows_success_notification(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(RegistrationPage::class)
            ->call('save')
            ->assertNotified('Registration settings saved');
    }

    public function test_default_role_can_be_null(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(RegistrationPage::class)
            ->set('data.default_role', null)
            ->call('save');

        $settings = app()->make(RegistrationSettings::class)->refresh();

        $this->assertNull($settings->default_role);
    }

    // ── self_registration_enabled enforcement ───────────────────────────────

    public function test_register_post_blocked_when_registration_disabled(): void
    {
        $settings = app(RegistrationSettings::class);
        $settings->self_registration_enabled = false;
        $settings->save();

        $this->post(route('auth.register.store'), [
            'first_name' => 'Test',
            'email' => 'new@sirieducation.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'terms' => '1',
        ])->assertForbidden();
    }

    public function test_register_get_redirects_when_registration_disabled(): void
    {
        $settings = app(RegistrationSettings::class);
        $settings->self_registration_enabled = false;
        $settings->save();

        $this->get(route('auth.register'))
            ->assertRedirect(route('auth.login'));
    }

    public function test_register_get_accessible_when_registration_enabled(): void
    {
        $settings = app(RegistrationSettings::class);
        $settings->self_registration_enabled = true;
        $settings->save();

        $this->get(route('auth.register'))->assertOk();
    }

    // ── Audit coverage via the shared atomic+audited helper ──────────────────

    public function test_save_creates_an_audit_event_with_the_diff(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(RegistrationPage::class)
            ->set('data.self_registration_enabled', true)
            ->call('save');

        $activity = Activity::where('log_name', 'security')
            ->where('event', 'settings_updated')
            ->where('properties->settings_class', RegistrationSettings::class)
            ->first();

        $this->assertNotNull($activity);
        $this->assertFalse($activity->properties['changed']['self_registration_enabled']['from']);
        $this->assertTrue($activity->properties['changed']['self_registration_enabled']['to']);
    }

    public function test_saving_with_no_changes_creates_no_audit_event(): void
    {
        $this->actingAs($this->superAdmin);

        $settings = app(RegistrationSettings::class);

        Livewire::test(RegistrationPage::class)
            ->set('data.self_registration_enabled', $settings->self_registration_enabled)
            ->set('data.default_role', $settings->default_role)
            ->set('data.require_admin_approval', $settings->require_admin_approval)
            ->set('data.send_welcome_email', $settings->send_welcome_email)
            ->set('data.auto_verify_email', $settings->auto_verify_email)
            ->call('save');

        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'security',
            'event' => 'settings_updated',
        ]);
    }
}
