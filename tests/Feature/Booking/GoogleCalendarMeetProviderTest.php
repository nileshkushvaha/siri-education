<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\GoogleCalendarClient;
use App\Booking\DTOs\MeetingCreationContext;
use App\Booking\DTOs\MeetingUpdateContext;
use App\Booking\Enums\MeetingStatus;
use App\Booking\Exceptions\BookingException;
use App\Booking\Meetings\GoogleCalendarMeetProvider;
use App\Booking\Services\GoogleCalendarConfigurationService;
use App\Booking\Services\MeetingProviderResolver;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Tests\TestCase;

class GoogleCalendarMeetProviderTest extends TestCase
{
    use RefreshDatabase;

    private function configureGoogle(bool $enabled = true): void
    {
        $settings = app(MeetingSettings::class);
        $settings->google_meet_enabled = $enabled;
        $settings->google_auth_type = 'service_account';
        $settings->google_calendar_id = 'calendar-123@group.calendar.google.com';
        $settings->google_credentials_json = Crypt::encryptString(
            json_encode(['type' => 'service_account', 'client_email' => 'svc@project.iam.gserviceaccount.com', 'private_key' => 'FAKE_PRIVATE_KEY_TOKEN_ABCDEFGHIJKLMNOP']),
        );
        $settings->save();
    }

    private function bindFakeClient(): GoogleCalendarClient&Mockery\MockInterface
    {
        $client = Mockery::mock(GoogleCalendarClient::class);
        $this->app->instance(GoogleCalendarClient::class, $client);

        return $client;
    }

    // ── Configuration ────────────────────────────────────────────────

    public function test_google_provider_is_not_configured_when_disabled(): void
    {
        $this->configureGoogle(enabled: false);

        $this->assertFalse(app(GoogleCalendarMeetProvider::class)->isConfigured());
    }

    public function test_google_provider_does_not_run_when_unconfigured(): void
    {
        $this->configureGoogle(enabled: false);

        $this->expectException(BookingException::class);
        app(MeetingProviderResolver::class)->resolve(GoogleCalendarMeetProvider::KEY);
    }

    public function test_configuration_service_reports_ready_when_fully_configured(): void
    {
        $this->configureGoogle();

        $this->assertSame('ready', app(GoogleCalendarConfigurationService::class)->check());
    }

    public function test_configuration_service_reports_incomplete_without_calendar_id(): void
    {
        $this->configureGoogle();
        $settings = app(MeetingSettings::class);
        $settings->google_calendar_id = null;
        $settings->save();

        $this->assertSame('incomplete', app(GoogleCalendarConfigurationService::class)->check());
    }

    // ── Event creation ───────────────────────────────────────────────

    public function test_google_provider_uses_configured_calendar_id(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();
        $booking = Booking::factory()->confirmed()->paid()->create();

        $client->shouldReceive('insertEvent')
            ->once()
            ->withArgs(fn (string $credentials, string $calendarId): bool => $calendarId === 'calendar-123@group.calendar.google.com')
            ->andReturn(['id' => 'evt1', 'hangoutLink' => null, 'conferenceData' => []]);

        app(GoogleCalendarMeetProvider::class)->createMeeting($booking, new MeetingCreationContext);
    }

    public function test_google_provider_sends_conference_data_request_and_safe_event_details(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();
        $booking = Booking::factory()->confirmed()->paid()->create(['price' => 499.00, 'currency' => 'INR']);

        $client->shouldReceive('insertEvent')
            ->once()
            ->withArgs(function (string $credentials, string $calendarId, array $payload): bool {
                $this->assertArrayHasKey('conferenceRequestId', $payload);
                $this->assertStringNotContainsString('499', $payload['description']);
                $this->assertStringNotContainsString('INR', $payload['description']);

                return true;
            })
            ->andReturn(['id' => 'evt1', 'hangoutLink' => null, 'conferenceData' => []]);

        app(GoogleCalendarMeetProvider::class)->createMeeting($booking, new MeetingCreationContext);
    }

    public function test_google_provider_stores_event_id_and_join_url_when_returned(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();
        $booking = Booking::factory()->confirmed()->paid()->create();

        $client->shouldReceive('insertEvent')->once()->andReturn([
            'id' => 'evt_success',
            'hangoutLink' => 'https://meet.google.com/abc-defg-hij',
            'conferenceData' => [
                'conferenceId' => 'conf_success',
                'status' => 'success',
                'entryPoints' => [
                    ['entryPointType' => 'video', 'uri' => 'https://meet.google.com/abc-defg-hij'],
                ],
            ],
        ]);

        $result = app(GoogleCalendarMeetProvider::class)->createMeeting($booking, new MeetingCreationContext);

        $this->assertSame(MeetingStatus::Created, $result->status);
        $this->assertSame('evt_success', $result->providerEventId);
        $this->assertSame('conf_success', $result->providerMeetingId);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $result->joinUrl);
    }

    public function test_google_provider_handles_pending_conference_creation_safely(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();
        $booking = Booking::factory()->confirmed()->paid()->create();

        $client->shouldReceive('insertEvent')->once()->andReturn([
            'id' => 'evt_pending',
            'hangoutLink' => null,
            'conferenceData' => ['status' => 'pending', 'entryPoints' => []],
        ]);

        $result = app(GoogleCalendarMeetProvider::class)->createMeeting($booking, new MeetingCreationContext);

        $this->assertSame(MeetingStatus::Pending, $result->status);
        $this->assertNull($result->joinUrl);
        $this->assertSame('evt_pending', $result->providerEventId);
    }

    public function test_google_provider_handles_failure_safely_without_exposing_credentials(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();
        $booking = Booking::factory()->confirmed()->paid()->create();

        $client->shouldReceive('insertEvent')
            ->once()
            ->andThrow(new \RuntimeException('Request failed for token AAAAAAAAAAAAAAAAAAAAABBBBBBBBBB1234567890'));

        try {
            app(GoogleCalendarMeetProvider::class)->createMeeting($booking, new MeetingCreationContext);
            $this->fail('Expected a BookingException.');
        } catch (BookingException $e) {
            $this->assertStringNotContainsString('AAAAAAAAAAAAAAAAAAAAABBBBBBBBBB1234567890', $e->getMessage());
            $this->assertStringContainsString('[redacted]', $e->getMessage());
        }
    }

    public function test_google_provider_retry_uses_update_event_when_provider_event_id_exists(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();
        $meeting = BookingMeeting::factory()->google()->failed()->create(['provider_event_id' => 'evt_existing']);

        $client->shouldReceive('updateEvent')
            ->once()
            ->withArgs(fn (string $credentials, string $calendarId, string $eventId): bool => $eventId === 'evt_existing')
            ->andReturn([
                'id' => 'evt_existing',
                'hangoutLink' => 'https://meet.google.com/retry-ok',
                'conferenceData' => ['status' => 'success', 'entryPoints' => [['entryPointType' => 'video', 'uri' => 'https://meet.google.com/retry-ok']]],
            ]);

        $result = app(GoogleCalendarMeetProvider::class)->updateMeeting($meeting, new MeetingUpdateContext);

        $this->assertSame(MeetingStatus::Created, $result->status);
        $this->assertSame('https://meet.google.com/retry-ok', $result->joinUrl);
    }

    public function test_google_provider_never_stores_raw_credentials_in_metadata(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();
        $booking = Booking::factory()->confirmed()->paid()->create();

        $client->shouldReceive('insertEvent')->once()->andReturn([
            'id' => 'evt1',
            'hangoutLink' => 'https://meet.google.com/safe',
            'conferenceData' => ['status' => 'success', 'entryPoints' => [['entryPointType' => 'video', 'uri' => 'https://meet.google.com/safe']]],
        ]);

        $result = app(GoogleCalendarMeetProvider::class)->createMeeting($booking, new MeetingCreationContext);

        $this->assertArrayNotHasKey('credentials', $result->metadata);
        $this->assertStringNotContainsString('FAKE_PRIVATE_KEY_TOKEN', json_encode($result->metadata));
    }
}
