<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Contracts\ZoomMeetingClient;
use App\Booking\Meetings\ZoomMeetingProvider;
use App\Booking\Services\RecordingAvailabilityResolver;
use App\Filament\Pages\Settings\MeetingSettingsPage;
use App\Filament\Pages\Settings\PlatformFoundationSettingsPage;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\User;
use App\Settings\FeatureSettings;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS §20.8: FeatureSettings::recording_enabled is the platform-wide
 * outer switch over MeetingSettings::recording_enabled. These tests
 * cover RecordingAvailabilityResolver in isolation and the enforcement
 * boundaries around it — not the full capture/storage/retention
 * pipeline, which is covered by RecordingService/RecordingCaptureJobAndSweepTest.
 */
class RecordingFeatureToggleTest extends TestCase
{
    use RefreshDatabase;

    private const ZOOM_SECRET = 'zoom_test_client_secret_value';

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');

        return $admin;
    }

    private function student(): User
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        return $student;
    }

    private function resolver(): RecordingAvailabilityResolver
    {
        return app(RecordingAvailabilityResolver::class);
    }

    private function setFlags(bool $global, bool $inner): void
    {
        $features = app(FeatureSettings::class);
        $features->recording_enabled = $global;
        $features->save();

        $meeting = app(MeetingSettings::class);
        $meeting->recording_enabled = $inner;
        $meeting->save();
    }

    private function configureZoom(): void
    {
        $settings = app(MeetingSettings::class);
        $settings->meetings_enabled = true;
        $settings->manual_provider_enabled = true;
        $settings->default_provider = 'manual';
        $settings->create_after_demo_booking_confirmation = true;
        $settings->create_after_paid_booking_confirmation = true;
        $settings->zoom_enabled = true;
        $settings->zoom_account_id = 'acct_123';
        $settings->zoom_client_id = 'client_abc';
        $settings->zoom_client_secret = Crypt::encryptString(self::ZOOM_SECRET);
        $settings->zoom_host_user_id = 'host-user-1';
        $settings->save();
    }

    private function bindFakeZoomClient(): ZoomMeetingClient&Mockery\MockInterface
    {
        $client = Mockery::mock(ZoomMeetingClient::class);
        $this->app->instance(ZoomMeetingClient::class, $client);

        return $client;
    }

    private function sanitizedZoomMeeting(): array
    {
        return [
            'id' => '987654321',
            'join_url' => 'https://zoom.us/j/987654321',
            'start_url' => 'https://zoom.us/s/987654321?zak=host-start-token',
            'password' => 'p4ss',
            'timezone' => 'UTC',
            'status' => 'waiting',
        ];
    }

    private function eligibleBooking(): Booking
    {
        return Booking::factory()->confirmed()->paid()->create();
    }

    // ── Feature resolution ───────────────────────────────────────────

    public function test_resolver_is_unavailable_when_global_flag_is_off_and_inner_flag_is_on(): void
    {
        $this->setFlags(global: false, inner: true);

        $this->assertFalse($this->resolver()->isAvailable());
    }

    public function test_resolver_is_unavailable_when_global_flag_is_off_and_inner_flag_is_off(): void
    {
        $this->setFlags(global: false, inner: false);

        $this->assertFalse($this->resolver()->isAvailable());
    }

    public function test_resolver_is_unavailable_when_global_flag_is_on_and_inner_flag_is_off(): void
    {
        $this->setFlags(global: true, inner: false);

        $this->assertFalse($this->resolver()->isAvailable());
    }

    public function test_resolver_is_available_only_when_both_global_and_inner_flags_are_on(): void
    {
        $this->setFlags(global: true, inner: true);

        $this->assertTrue($this->resolver()->isAvailable());
    }

    public function test_missing_explicit_configuration_defaults_closed(): void
    {
        // Neither flag has ever been explicitly saved by an admin —
        // the installed settings defaults must fail closed.
        $this->assertFalse(app(FeatureSettings::class)->recording_enabled);
        $this->assertFalse(app(MeetingSettings::class)->recording_enabled);
        $this->assertFalse($this->resolver()->isAvailable());
    }

    public function test_repeated_resolution_does_not_mutate_stored_settings(): void
    {
        $this->setFlags(global: true, inner: true);

        $before = [
            app(FeatureSettings::class)->recording_enabled,
            app(MeetingSettings::class)->recording_enabled,
        ];

        $this->resolver()->isAvailable();
        $this->resolver()->isAvailable();

        $this->assertSame($before, [
            app(FeatureSettings::class)->refresh()->recording_enabled,
            app(MeetingSettings::class)->refresh()->recording_enabled,
        ]);
        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'settings',
            'event' => 'settings_updated',
        ]);
    }

    // ── Runtime enforcement / regression ─────────────────────────────

    public function test_zoom_never_requests_recording_from_the_provider_even_when_both_flags_are_enabled(): void
    {
        $this->setFlags(global: true, inner: true);
        $this->configureZoom();
        $client = $this->bindFakeZoomClient();
        $booking = $this->eligibleBooking();

        $client->shouldReceive('createMeeting')
            ->once()
            ->withArgs(function (string $hostUser, array $payload): bool {
                // The booking here is confirmed+paid but has no recorded
                // participant consent, so RecordingEligibilityResolver still
                // resolves ineligible even with both toggles on — auto_recording
                // stays 'none'.
                $this->assertSame('none', $payload['settings']['auto_recording']);

                return true;
            })
            ->andReturn($this->sanitizedZoomMeeting());

        $meeting = app(BookingMeetingServiceInterface::class)->createMeeting($booking, ZoomMeetingProvider::KEY);

        $this->assertNotNull($meeting);
    }

    public function test_meeting_creation_still_succeeds_when_recording_is_globally_disabled(): void
    {
        $this->setFlags(global: false, inner: false);
        $this->configureZoom();
        $client = $this->bindFakeZoomClient();
        $booking = $this->eligibleBooking();

        $client->shouldReceive('createMeeting')->once()->andReturn($this->sanitizedZoomMeeting());

        $meeting = app(BookingMeetingServiceInterface::class)->createMeeting($booking, ZoomMeetingProvider::KEY);

        $this->assertNotNull($meeting);
        $this->assertSame('https://zoom.us/j/987654321', $meeting->join_url);
    }

    public function test_toggling_the_global_flag_off_does_not_modify_an_existing_meeting_record(): void
    {
        $this->setFlags(global: true, inner: true);
        $this->configureZoom();
        $client = $this->bindFakeZoomClient();
        $booking = $this->eligibleBooking();
        $client->shouldReceive('createMeeting')->once()->andReturn($this->sanitizedZoomMeeting());

        $meeting = app(BookingMeetingServiceInterface::class)->createMeeting($booking, ZoomMeetingProvider::KEY);
        $before = $meeting->only(['join_url', 'host_url', 'password', 'provider_meeting_id', 'status']);

        $this->actingAs($this->superAdmin());
        Livewire::test(PlatformFoundationSettingsPage::class)
            ->set('data.recording_enabled', false)
            ->call('save');

        $meeting->refresh();
        $this->assertSame($before, $meeting->only(['join_url', 'host_url', 'password', 'provider_meeting_id', 'status']));
    }

    // ── Authorization, audit, and UI precedence ──────────────────────

    public function test_unauthorized_user_cannot_change_either_recording_flag(): void
    {
        $this->actingAs($this->student())
            ->get('/admin/settings/platform-foundation')
            ->assertForbidden();

        $this->actingAs($this->student())
            ->get('/admin/settings/meetings')
            ->assertForbidden();

        $this->assertFalse(app(FeatureSettings::class)->recording_enabled);
        $this->assertFalse(app(MeetingSettings::class)->recording_enabled);
    }

    public function test_authorized_admin_can_toggle_both_flags_and_the_effective_indicator_reflects_precedence(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(PlatformFoundationSettingsPage::class)
            ->set('data.recording_enabled', true)
            ->call('save')
            ->assertNotified('Platform foundation settings saved');

        Livewire::test(MeetingSettingsPage::class)
            ->set('data.meeting_recording_enabled', true)
            ->call('save')
            ->assertNotified('Meeting settings saved');

        $this->assertTrue($this->resolver()->isAvailable());

        Livewire::test(MeetingSettingsPage::class)
            ->assertSet('data.effective_recording_availability', 'Available');

        // Flipping only the global switch back off must immediately make
        // the meeting-level page reflect "Unavailable" again, even though
        // the inner default is still true.
        Livewire::test(PlatformFoundationSettingsPage::class)
            ->set('data.recording_enabled', false)
            ->call('save');

        $this->assertTrue(app(MeetingSettings::class)->recording_enabled);
        $this->assertFalse($this->resolver()->isAvailable());

        Livewire::test(MeetingSettingsPage::class)
            ->assertSet('data.effective_recording_availability', 'Unavailable');
    }

    public function test_saving_either_recording_flag_uses_the_established_audit_path(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(PlatformFoundationSettingsPage::class)
            ->set('data.recording_enabled', true)
            ->call('save');

        $featureEvent = Activity::query()
            ->where('event', 'settings_updated')
            ->where('properties->settings_class', FeatureSettings::class)
            ->latest('id')
            ->first();

        $this->assertNotNull($featureEvent);
        $this->assertArrayHasKey('recording_enabled', $featureEvent->properties['changed']);

        Livewire::test(MeetingSettingsPage::class)
            ->set('data.meeting_recording_enabled', true)
            ->call('save');

        $meetingEvent = Activity::query()
            ->where('event', 'settings_updated')
            ->where('properties->settings_class', MeetingSettings::class)
            ->latest('id')
            ->first();

        $this->assertNotNull($meetingEvent);
        $this->assertArrayHasKey('recording_enabled', $meetingEvent->properties['changed']);
    }
}
