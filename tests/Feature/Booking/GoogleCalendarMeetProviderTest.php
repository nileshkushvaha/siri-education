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

    private const DELEGATED_ACCOUNT = 'meetings@sirieducation.com';

    private function configureGoogle(bool $enabled = true): void
    {
        $settings = app(MeetingSettings::class);
        $settings->google_meet_enabled = $enabled;
        $settings->google_auth_type = 'service_account';
        $settings->google_calendar_id = 'calendar-123@group.calendar.google.com';
        $settings->platform_meeting_account = self::DELEGATED_ACCOUNT;
        $settings->google_credentials_json = Crypt::encryptString(
            json_encode(['type' => 'service_account', 'client_email' => 'svc@project.iam.gserviceaccount.com', 'private_key' => 'FAKE_PRIVATE_KEY_TOKEN_ABCDEFGHIJKLMNOP']),
        );
        $settings->save();
    }

    /** @param  list<string>  $allowedConferenceTypes */
    private function bindFakeClient(array $allowedConferenceTypes = ['hangoutsMeet']): GoogleCalendarClient&Mockery\MockInterface
    {
        $client = Mockery::mock(GoogleCalendarClient::class);
        $client->shouldReceive('allowedConferenceTypes')->andReturn($allowedConferenceTypes);
        $this->app->instance(GoogleCalendarClient::class, $client);

        return $client;
    }

    // ── Configuration ────────────────────────────────────────────────

    public function test_google_provider_is_not_configured_when_disabled(): void
    {
        $this->configureGoogle(enabled: false);

        $this->assertFalse(app(GoogleCalendarMeetProvider::class)->isConfigured());
    }

    public function test_google_provider_is_not_configured_without_platform_meeting_account(): void
    {
        $this->configureGoogle();
        $settings = app(MeetingSettings::class);
        $settings->platform_meeting_account = null;
        $settings->save();

        $this->assertFalse(app(GoogleCalendarMeetProvider::class)->isConfigured());
    }

    public function test_google_provider_does_not_run_when_unconfigured(): void
    {
        $this->configureGoogle(enabled: false);

        $this->expectException(BookingException::class);
        app(MeetingProviderResolver::class)->resolve(GoogleCalendarMeetProvider::KEY);
    }

    public function test_configuration_service_reports_incomplete_without_calendar_id(): void
    {
        $this->configureGoogle();
        $settings = app(MeetingSettings::class);
        $settings->google_calendar_id = null;
        $settings->save();

        $this->assertSame('incomplete', app(GoogleCalendarConfigurationService::class)->check());
    }

    public function test_configuration_service_reports_incomplete_without_platform_meeting_account(): void
    {
        $this->configureGoogle();
        $settings = app(MeetingSettings::class);
        $settings->platform_meeting_account = null;
        $settings->save();

        $this->assertSame('incomplete', app(GoogleCalendarConfigurationService::class)->check());
    }

    // ── Delegated subject (domain-wide delegation) ─────────────────────

    public function test_google_provider_sends_delegated_subject_to_client(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();
        $booking = Booking::factory()->confirmed()->paid()->create();

        $client->shouldReceive('insertEvent')
            ->once()
            ->withArgs(fn (string $credentials, string $calendarId, array $payload, ?string $subject): bool => $subject === self::DELEGATED_ACCOUNT)
            ->andReturn(['id' => 'evt1', 'hangoutLink' => null, 'conferenceData' => []]);

        app(GoogleCalendarMeetProvider::class)->createMeeting($booking, new MeetingCreationContext);
    }

    public function test_google_provider_reads_updated_platform_meeting_account(): void
    {
        $this->configureGoogle();
        $settings = app(MeetingSettings::class);
        $settings->platform_meeting_account = 'stale@sirieducation.com';
        $settings->save();
        $settings->platform_meeting_account = self::DELEGATED_ACCOUNT;
        $settings->save();

        $client = $this->bindFakeClient();
        $booking = Booking::factory()->confirmed()->paid()->create();

        $client->shouldReceive('insertEvent')
            ->once()
            ->withArgs(fn (string $credentials, string $calendarId, array $payload, ?string $subject): bool => $subject === self::DELEGATED_ACCOUNT)
            ->andReturn(['id' => 'evt1', 'hangoutLink' => null, 'conferenceData' => []]);

        app(GoogleCalendarMeetProvider::class)->createMeeting($booking, new MeetingCreationContext);
    }

    // ── Conference capability validation ────────────────────────────────

    public function test_google_provider_creates_meeting_when_hangouts_meet_is_supported(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient(['eventHangout', 'hangoutsMeet']);
        $booking = Booking::factory()->confirmed()->paid()->create();

        $client->shouldReceive('insertEvent')->once()->andReturn(['id' => 'evt1', 'hangoutLink' => null, 'conferenceData' => []]);

        app(GoogleCalendarMeetProvider::class)->createMeeting($booking, new MeetingCreationContext);
    }

    public function test_google_provider_rejects_meeting_when_hangouts_meet_is_not_in_allowed_types(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient(['eventHangout']);
        $booking = Booking::factory()->confirmed()->paid()->create();

        $client->shouldNotReceive('insertEvent');

        try {
            app(GoogleCalendarMeetProvider::class)->createMeeting($booking, new MeetingCreationContext);
            $this->fail('Expected a BookingException.');
        } catch (BookingException $e) {
            $this->assertStringContainsString('not supported', $e->getMessage());
            $this->assertStringContainsString('eventHangout', $e->getMessage());
        }
    }

    public function test_google_provider_rejects_meeting_when_calendar_reports_no_conference_types(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient([]);
        $booking = Booking::factory()->confirmed()->paid()->create();

        $client->shouldNotReceive('insertEvent');

        $this->expectException(BookingException::class);
        app(GoogleCalendarMeetProvider::class)->createMeeting($booking, new MeetingCreationContext);
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

    public function test_google_provider_creates_unique_non_empty_request_ids_per_booking(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();
        $bookingA = Booking::factory()->confirmed()->paid()->create();
        $bookingB = Booking::factory()->confirmed()->paid()->create();

        $seenRequestIds = [];
        $client->shouldReceive('insertEvent')
            ->twice()
            ->withArgs(function (string $credentials, string $calendarId, array $payload) use (&$seenRequestIds): bool {
                $this->assertNotEmpty($payload['conferenceRequestId']);
                $seenRequestIds[] = $payload['conferenceRequestId'];

                return true;
            })
            ->andReturn(['id' => 'evt1', 'hangoutLink' => null, 'conferenceData' => []]);

        app(GoogleCalendarMeetProvider::class)->createMeeting($bookingA, new MeetingCreationContext);
        app(GoogleCalendarMeetProvider::class)->createMeeting($bookingB, new MeetingCreationContext);

        $this->assertCount(2, array_unique($seenRequestIds));
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

    public function test_google_provider_converts_invalid_conference_type_error_into_safe_exception(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();
        $booking = Booking::factory()->confirmed()->paid()->create();

        // Simulates GoogleCalendarSdkClient::translateException()'s rich,
        // sanitized message for Google's real 400 "Invalid conference
        // type value." response — the provider must forward it (redacting
        // only token-shaped substrings), never swallow it or silently
        // fall back to another provider.
        $client->shouldReceive('insertEvent')
            ->once()
            ->andThrow(new \RuntimeException(
                'Google Calendar API error (HTTP 400, reason: invalid): Invalid conference type value. '
                .'Calendar: calendar-123@group.calendar.google.com. Delegated account: meetings@sirieducation.com. '
                .'Requested conference type: hangoutsMeet.',
            ));

        try {
            app(GoogleCalendarMeetProvider::class)->createMeeting($booking, new MeetingCreationContext);
            $this->fail('Expected a BookingException.');
        } catch (BookingException $e) {
            $this->assertStringContainsString('Invalid conference type value', $e->getMessage());
            $this->assertStringContainsString('400', $e->getMessage());
            $this->assertStringContainsString('hangoutsMeet', $e->getMessage());
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
