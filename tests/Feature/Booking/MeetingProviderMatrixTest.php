<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\DiscoversRecordingArtifacts;
use App\Booking\Contracts\MeetingProviderInterface;
use App\Booking\Contracts\MeetingRecordingProviderInterface;
use App\Booking\Contracts\RecordingWebhookProvider;
use App\Booking\Exceptions\BookingException;
use App\Booking\Meetings\GoogleCalendarMeetProvider;
use App\Booking\Meetings\ManualMeetingProvider;
use App\Booking\Meetings\ZoomMeetingProvider;
use App\Booking\Services\MeetingProviderResolver;
use App\Models\BookingMeeting;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Google Meet and Zoom as peers, and the rule that keeps them peers:
 *
 *     WHICH PROVIDER creates the meeting
 *   is independent of
 *     WHETHER that provider records.
 *
 * Every combination below must be expressible. The failure this guards
 * against is the tempting shortcut "Zoom means recording, Meet means no
 * recording" — a coupling that would make the two settings impossible
 * to reason about and would surprise an admin who enabled a provider
 * and silently started recording lessons.
 */
final class MeetingProviderMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = app(MeetingSettings::class);
        $settings->meetings_enabled = true;
        $settings->save();
    }

    private function configureGoogle(bool $enabled = true, bool $recording = false): void
    {
        $settings = app(MeetingSettings::class);
        $settings->google_meet_enabled = $enabled;
        $settings->google_auth_type = 'service_account';
        $settings->google_calendar_id = 'calendar-1';
        $settings->platform_meeting_account = 'classes@example.test';
        $settings->google_credentials_json = Crypt::encryptString('{"client_email":"svc@example.test","client_id":"1"}');
        $settings->google_meet_recording_enabled = $recording;
        $settings->save();
    }

    private function configureZoom(bool $enabled = true, bool $recording = false): void
    {
        $settings = app(MeetingSettings::class);
        $settings->zoom_enabled = $enabled;
        $settings->zoom_account_id = 'acct-1';
        $settings->zoom_client_id = 'client-1';
        $settings->zoom_client_secret = Crypt::encryptString('shhh');
        $settings->zoom_host_user_id = 'host-1';
        $settings->zoom_recording_enabled = $recording;
        $settings->save();
    }

    private function google(): GoogleCalendarMeetProvider
    {
        return app(GoogleCalendarMeetProvider::class);
    }

    private function zoom(): ZoomMeetingProvider
    {
        return app(ZoomMeetingProvider::class);
    }

    // ── The four recording combinations ───────────────────────────────

    public function test_google_meet_enabled_with_recording_off(): void
    {
        $this->configureGoogle(enabled: true, recording: false);

        $this->assertTrue($this->google()->isConfigured());
        $this->assertFalse($this->google()->supportsRecording());
    }

    public function test_google_meet_enabled_with_recording_on(): void
    {
        $this->configureGoogle(enabled: true, recording: true);

        $this->assertTrue($this->google()->isConfigured());
        $this->assertTrue($this->google()->supportsRecording());
    }

    public function test_zoom_enabled_with_recording_off(): void
    {
        $this->configureZoom(enabled: true, recording: false);

        $this->assertTrue($this->zoom()->isConfigured());
        $this->assertFalse($this->zoom()->supportsRecording());
    }

    public function test_zoom_enabled_with_recording_on(): void
    {
        $this->configureZoom(enabled: true, recording: true);

        $this->assertTrue($this->zoom()->isConfigured());
        $this->assertTrue($this->zoom()->supportsRecording());
    }

    /** Both providers usable at once, with opposite recording settings. */
    public function test_both_providers_can_be_enabled_with_independent_recording_settings(): void
    {
        $this->configureGoogle(enabled: true, recording: false);
        $this->configureZoom(enabled: true, recording: true);

        $this->assertTrue($this->google()->isConfigured());
        $this->assertTrue($this->zoom()->isConfigured());
        $this->assertFalse($this->google()->supportsRecording());
        $this->assertTrue($this->zoom()->supportsRecording());
    }

    /**
     * Recording cannot outlive its provider: switching a provider off
     * must take its recording capability with it, whatever the
     * recording flag says.
     */
    public function test_a_disabled_provider_never_reports_recording_support(): void
    {
        $this->configureZoom(enabled: false, recording: true);

        $this->assertFalse($this->zoom()->isConfigured());
        $this->assertFalse($this->zoom()->supportsRecording());
    }

    // ── Manual provider ───────────────────────────────────────────────

    /**
     * The manual provider must NOT have become recordable just because
     * the recording pipeline now serves more than one provider. There
     * is no API behind it and no artifact to fetch.
     */
    public function test_the_manual_provider_is_not_recording_capable(): void
    {
        $manual = app(ManualMeetingProvider::class);

        $this->assertNotInstanceOf(MeetingRecordingProviderInterface::class, $manual);
        $this->assertNotInstanceOf(DiscoversRecordingArtifacts::class, $manual);
        $this->assertNotInstanceOf(RecordingWebhookProvider::class, $manual);
    }

    // ── Provider selection ────────────────────────────────────────────

    public function test_the_configured_default_provider_is_what_gets_resolved(): void
    {
        $this->configureGoogle();
        $this->configureZoom();

        $settings = app(MeetingSettings::class);
        $settings->default_provider = ZoomMeetingProvider::KEY;
        $settings->save();

        $this->assertInstanceOf(ZoomMeetingProvider::class, app(MeetingProviderResolver::class)->current());

        $settings->default_provider = GoogleCalendarMeetProvider::KEY;
        $settings->save();

        $this->assertInstanceOf(GoogleCalendarMeetProvider::class, app(MeetingProviderResolver::class)->current());
    }

    /**
     * A broken provider must FAIL, not quietly produce a different kind
     * of meeting. Silently falling back to Meet would mean an admin who
     * mis-typed a Zoom credential gets Google Meet lessons without ever
     * being told.
     */
    public function test_an_unconfigured_provider_fails_closed_rather_than_falling_back(): void
    {
        $this->configureGoogle();
        $this->configureZoom(enabled: false);

        $settings = app(MeetingSettings::class);
        $settings->default_provider = ZoomMeetingProvider::KEY;
        $settings->save();

        $this->expectException(BookingException::class);

        app(MeetingProviderResolver::class)->current();
    }

    public function test_the_platform_kill_switch_stops_every_provider(): void
    {
        $this->configureGoogle();
        $settings = app(MeetingSettings::class);
        $settings->meetings_enabled = false;
        $settings->default_provider = GoogleCalendarMeetProvider::KEY;
        $settings->save();

        $this->expectException(BookingException::class);

        app(MeetingProviderResolver::class)->current();
    }

    /**
     * Changing the default must affect only FUTURE meetings. An
     * already-created meeting is operated through the provider recorded
     * on its own row — otherwise switching the default would try to
     * cancel a Google Meet conference through Zoom's API.
     */
    public function test_an_existing_meeting_keeps_its_own_provider_after_the_default_changes(): void
    {
        $this->configureGoogle();
        $this->configureZoom();

        $settings = app(MeetingSettings::class);
        $settings->default_provider = GoogleCalendarMeetProvider::KEY;
        $settings->save();

        $meeting = BookingMeeting::factory()->created()->create([
            'provider' => GoogleCalendarMeetProvider::KEY,
        ]);

        // The platform switches its default to Zoom.
        $settings->default_provider = ZoomMeetingProvider::KEY;
        $settings->save();

        $this->assertInstanceOf(ZoomMeetingProvider::class, app(MeetingProviderResolver::class)->current());
        // …but this meeting is still resolved as Google Meet.
        $this->assertInstanceOf(
            GoogleCalendarMeetProvider::class,
            app(MeetingProviderResolver::class)->resolve($meeting->fresh()->provider),
        );
    }

    /**
     * The cancellation path must resolve from the persisted provider,
     * never from the current default — asserted structurally because
     * the failure mode (cancelling meeting A through provider B) is
     * silent and only shows up as an orphaned provider-side meeting.
     */
    public function test_meeting_cancellation_resolves_the_persisted_provider(): void
    {
        $source = php_strip_whitespace(app_path('Booking/Services/BookingMeetingService.php'));

        $this->assertStringContainsString('resolve($meeting->provider)', $source);
    }

    // ── Capability discovery, not provider sniffing ───────────────────

    /**
     * The application asks "does this provider support X?", never "is
     * this Zoom?". Both production providers implement the same
     * contracts; only their capability answers differ.
     */
    public function test_both_production_providers_implement_the_shared_contracts(): void
    {
        foreach ([$this->google(), $this->zoom()] as $provider) {
            $this->assertInstanceOf(MeetingProviderInterface::class, $provider);
            $this->assertInstanceOf(MeetingRecordingProviderInterface::class, $provider);
            $this->assertInstanceOf(DiscoversRecordingArtifacts::class, $provider);
        }
    }

    /**
     * Webhook capability legitimately differs — Zoom pushes, Google
     * does not — and that difference is expressed by an interface
     * rather than by a conditional somewhere in a controller.
     */
    public function test_only_zoom_declares_recording_webhook_capability(): void
    {
        $this->assertInstanceOf(RecordingWebhookProvider::class, $this->zoom());
        $this->assertNotInstanceOf(RecordingWebhookProvider::class, $this->google());
    }
}
