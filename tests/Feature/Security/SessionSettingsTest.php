<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Filament\Pages\Security\SessionPage;
use App\Models\Activity;
use App\Models\User;
use App\Services\Security\AdminSessionService;
use App\Settings\SessionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SessionSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/settings']);

        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'security.session.view',   'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'security.session.update', 'guard_name' => 'web']);

        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $this->superAdmin->assignRole($superAdminRole);

        $this->regularUser = User::factory()->create(['status' => 'active']);
    }

    // ── Access control ─────────────────────────────────────────────────────

    public function test_super_admin_can_access_session_page(): void
    {
        $this->actingAs($this->superAdmin)
            ->get('/admin/security/session')
            ->assertOk();
    }

    public function test_regular_user_cannot_access_session_page(): void
    {
        $this->actingAs($this->regularUser)
            ->get('/admin/security/session')
            ->assertForbidden();
    }

    // ── Default values ──────────────────────────────────────────────────────

    public function test_default_values_are_seeded_correctly(): void
    {
        $settings = app(SessionSettings::class);

        $this->assertSame(120, $settings->idle_timeout);
        $this->assertTrue($settings->allow_multiple_sessions);
        $this->assertTrue($settings->force_logout_on_password_change);
        $this->assertFalse($settings->trusted_devices_enabled);
        $this->assertFalse($settings->device_management_enabled);
    }

    // ── Settings persistence ────────────────────────────────────────────────

    public function test_save_persists_settings(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(SessionPage::class)
            ->set('data.idle_timeout', 60)
            ->set('data.allow_multiple_sessions', false)
            ->set('data.force_logout_on_password_change', false)
            ->call('save');

        $settings = app()->make(SessionSettings::class)->refresh();

        $this->assertSame(60, $settings->idle_timeout);
        $this->assertFalse($settings->allow_multiple_sessions);
        $this->assertFalse($settings->force_logout_on_password_change);
    }

    public function test_save_shows_success_notification(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(SessionPage::class)
            ->call('save')
            ->assertNotified('Session settings saved');
    }

    // ── Validation ──────────────────────────────────────────────────────────

    public function test_idle_timeout_must_be_at_least_one(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(SessionPage::class)
            ->set('data.idle_timeout', 0)
            ->call('save')
            ->assertHasErrors(['data.idle_timeout']);
    }

    // ── Service layer ───────────────────────────────────────────────────────

    public function test_session_service_clears_remember_tokens(): void
    {
        $user1 = User::factory()->create(['remember_token' => 'token-abc', 'status' => 'active']);
        $user2 = User::factory()->create(['remember_token' => 'token-xyz', 'status' => 'active']);

        $this->actingAs($this->superAdmin);

        app(AdminSessionService::class)->forceLogoutAllDevices();

        $this->assertNull($user1->fresh()->remember_token);
        $this->assertNull($user2->fresh()->remember_token);
    }

    public function test_force_logout_action_creates_activity_log(): void
    {
        $this->actingAs($this->superAdmin);

        app(AdminSessionService::class)->forceLogoutAllDevices();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'force_logout_all',
        ]);
    }

    public function test_force_logout_action_shows_notification(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(SessionPage::class)
            ->call('forceLogoutAll')
            ->assertNotified('All sessions terminated');
    }

    // ── Phase 24S: settings-save audit coverage (distinct from forceLogoutAll's own event) ──

    public function test_save_creates_a_settings_updated_audit_event_with_the_diff(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(SessionPage::class)
            ->set('data.idle_timeout', 60)
            ->call('save');

        $activity = Activity::where('log_name', 'security')
            ->where('event', 'settings_updated')
            ->where('properties->settings_class', SessionSettings::class)
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(120, $activity->properties['changed']['idle_timeout']['from']);
        $this->assertSame(60, $activity->properties['changed']['idle_timeout']['to']);
    }

    public function test_saving_with_no_changes_creates_no_settings_updated_audit_event(): void
    {
        $this->actingAs($this->superAdmin);

        $settings = app(SessionSettings::class);

        Livewire::test(SessionPage::class)
            ->set('data.idle_timeout', $settings->idle_timeout)
            ->set('data.allow_multiple_sessions', $settings->allow_multiple_sessions)
            ->set('data.force_logout_on_password_change', $settings->force_logout_on_password_change)
            ->call('save');

        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'security',
            'event' => 'settings_updated',
        ]);
    }
}
