<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Filament\Pages\Security\AccountProtectionPage;
use App\Models\User;
use App\Settings\AccountProtectionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountProtectionSettingsTest extends TestCase
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
        $permission = Permission::firstOrCreate(['name' => 'security.account_protection.view',   'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'security.account_protection.update', 'guard_name' => 'web']);

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

    public function test_super_admin_can_access_account_protection_page(): void
    {
        $this->actingAs($this->superAdmin)
            ->get('/admin/security/account-protection')
            ->assertOk();
    }

    public function test_user_with_permission_can_access_account_protection_page(): void
    {
        $this->actingAs($this->securityUser)
            ->get('/admin/security/account-protection')
            ->assertOk();
    }

    public function test_regular_user_cannot_access_account_protection_page(): void
    {
        $this->actingAs($this->regularUser)
            ->get('/admin/security/account-protection')
            ->assertForbidden();
    }

    // ── Default values ──────────────────────────────────────────────────────

    public function test_default_values_are_seeded_correctly(): void
    {
        $settings = app(AccountProtectionSettings::class);

        $this->assertTrue($settings->disable_after_failed_attempts);
        $this->assertSame(30, $settings->auto_unlock_after);
        $this->assertTrue($settings->notify_user);
        $this->assertFalse($settings->notify_admin);
        $this->assertFalse($settings->login_history_enabled);
        $this->assertFalse($settings->suspicious_login_detection);
        $this->assertFalse($settings->ip_restriction_enabled);
        $this->assertFalse($settings->device_restriction_enabled);
    }

    // ── Settings persistence ────────────────────────────────────────────────

    public function test_save_persists_settings(): void
    {
        $this->actingAs($this->superAdmin);

        // auto_unlock_after is only visible (and submitted) when disable_after_failed_attempts=true
        Livewire::test(AccountProtectionPage::class)
            ->set('data.disable_after_failed_attempts', true)
            ->set('data.auto_unlock_after', 60)
            ->set('data.notify_user', false)
            ->set('data.notify_admin', true)
            ->call('save');

        $settings = app()->make(AccountProtectionSettings::class)->refresh();

        $this->assertTrue($settings->disable_after_failed_attempts);
        $this->assertSame(60, $settings->auto_unlock_after);
        $this->assertFalse($settings->notify_user);
        $this->assertTrue($settings->notify_admin);
    }

    public function test_save_shows_success_notification(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(AccountProtectionPage::class)
            ->call('save')
            ->assertNotified('Account protection settings saved');
    }

    // ── Validation ──────────────────────────────────────────────────────────

    public function test_auto_unlock_after_must_be_non_negative(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(AccountProtectionPage::class)
            ->set('data.auto_unlock_after', -1)
            ->call('save')
            ->assertHasErrors(['data.auto_unlock_after']);
    }

    // ── Activity log ────────────────────────────────────────────────────────

    public function test_save_creates_activity_log_entry(): void
    {
        $this->actingAs($this->superAdmin);

        // A save with no actual field change is correctly a no-op audit
        // event — flip a real value so this proves a genuine
        // change is recorded.
        Livewire::test(AccountProtectionPage::class)
            ->set('data.notify_admin', true)
            ->call('save');

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'settings_updated',
        ]);
    }

    // ── Independent permission gates ────────────────────────────────────────

    public function test_account_protection_permission_is_independent_from_other_security_pages(): void
    {
        $otherPermission = Permission::firstOrCreate(['name' => 'security.authentication.view', 'guard_name' => 'web']);

        $partialUser = User::factory()->create(['status' => 'active']);
        $partialUser->givePermissionTo($otherPermission);

        $this->actingAs($partialUser)
            ->get('/admin/security/account-protection')
            ->assertForbidden();
    }
}
