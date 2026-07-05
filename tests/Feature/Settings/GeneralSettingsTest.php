<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Filament\Pages\Settings\GeneralSettingsPage;
use App\Models\Activity;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GeneralSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/settings']);
    }

    public function test_general_settings_load_defaults(): void
    {
        $settings = app(GeneralSettings::class);

        $this->assertSame('Asia/Kolkata', $settings->default_timezone);
        $this->assertSame('en', $settings->default_language);
        $this->assertSame('INR', $settings->default_currency);
        $this->assertFalse($settings->maintenance_mode);
    }

    public function test_admin_can_update_general_settings(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

        $this->actingAs($admin);

        Livewire::test(GeneralSettingsPage::class)
            ->set('data.app_name', 'Sphere Education')
            ->set('data.support_email', 'support@example.com')
            ->set('data.default_timezone', 'America/New_York')
            ->set('data.default_language', 'fr')
            ->set('data.date_format', 'd/m/Y')
            ->set('data.time_format', 'h:i A')
            ->set('data.default_currency', 'USD')
            ->set('data.decimal_precision', 2)
            ->set('data.homepage_display', 'template')
            ->call('save')
            ->assertNotified('General settings saved');

        $settings = app()->make(GeneralSettings::class)->refresh();

        $this->assertSame('America/New_York', $settings->default_timezone);
        $this->assertSame('fr', $settings->default_language);
        $this->assertSame('USD', $settings->default_currency);
    }

    public function test_updating_general_settings_logs_an_audit_entry_with_the_diff(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
        $this->actingAs($admin);

        Livewire::test(GeneralSettingsPage::class)
            ->set('data.app_name', 'Sphere Education')
            ->set('data.support_email', 'support@example.com')
            ->set('data.default_timezone', 'America/New_York')
            ->set('data.default_language', 'fr')
            ->set('data.date_format', 'd/m/Y')
            ->set('data.time_format', 'h:i A')
            ->set('data.default_currency', 'USD')
            ->set('data.decimal_precision', 2)
            ->set('data.homepage_display', 'template')
            ->call('save');

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'settings',
            'event' => 'settings_updated',
            'causer_id' => $admin->id,
        ]);

        $activity = Activity::where('event', 'settings_updated')->firstOrFail();
        $changed = $activity->properties->get('changed');
        $this->assertSame('America/New_York', $changed['default_timezone']['to']);
    }

    public function test_saving_unchanged_general_settings_does_not_log(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
        $this->actingAs($admin);

        $settings = app(GeneralSettings::class);

        Livewire::test(GeneralSettingsPage::class)
            ->set('data.app_name', $settings->app_name)
            ->set('data.support_email', $settings->support_email)
            ->set('data.default_timezone', $settings->default_timezone)
            ->set('data.default_language', $settings->default_language)
            ->set('data.date_format', $settings->date_format)
            ->set('data.time_format', $settings->time_format)
            ->set('data.default_currency', $settings->default_currency)
            ->set('data.decimal_precision', $settings->decimal_precision)
            ->set('data.homepage_display', $settings->homepage_display)
            ->call('save');

        $this->assertDatabaseMissing('activity_log', ['log_name' => 'settings', 'event' => 'settings_updated']);
    }
}
