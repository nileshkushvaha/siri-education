<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Booking\Contracts\ZoomMeetingClient;
use App\Filament\Pages\Settings\MeetingSettingsPage;
use App\Filament\Pages\Settings\PlatformFoundationSettingsPage;
use App\Models\User;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MeetingSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_meeting_settings_page_renders_for_super_admin(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/admin/settings/meetings')
            ->assertOk()
            ->assertSee('Meeting Settings');
    }

    public function test_meeting_settings_page_denies_users_without_settings_access(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->actingAs($user)->get('/admin/settings/meetings')->assertForbidden();
    }

    public function test_meeting_settings_page_saves_meeting_fields(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(MeetingSettingsPage::class)
            ->set('data.meetings_enabled', true)
            ->set('data.default_provider', 'google_meet')
            ->set('data.meeting_recording_enabled', true)
            ->set('data.create_after_paid_booking_confirmation', false)
            ->set('data.student_join_url_visible', false)
            ->call('save')
            ->assertNotified('Meeting settings saved');

        $settings = app()->make(MeetingSettings::class)->refresh();
        $this->assertTrue($settings->meetings_enabled);
        $this->assertSame('google_meet', $settings->default_provider);
        $this->assertTrue($settings->recording_enabled);
        $this->assertFalse($settings->create_after_paid_booking_confirmation);
        $this->assertFalse($settings->student_join_url_visible);
    }

    public function test_platform_foundation_page_no_longer_exposes_meeting_fields(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(PlatformFoundationSettingsPage::class)
            ->assertFormFieldDoesNotExist('default_provider')
            ->assertFormFieldDoesNotExist('zoom_client_secret')
            ->assertFormFieldDoesNotExist('google_credentials_json');
    }

    public function test_validate_zoom_configuration_action_reports_status(): void
    {
        // Unconfigured: fails closed without ever touching the client.
        $client = Mockery::mock(ZoomMeetingClient::class);
        $client->shouldNotReceive('validateCredentials');
        $this->app->instance(ZoomMeetingClient::class, $client);

        $this->actingAs($this->superAdmin());

        Livewire::test(MeetingSettingsPage::class)
            ->call('validateZoomConfiguration')
            ->assertNotified('Zoom configuration: not_configured');
    }

    public function test_test_google_configuration_action_reports_status(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(MeetingSettingsPage::class)
            ->call('testGoogleConfiguration')
            ->assertNotified('Google configuration: not_configured');
    }
}
