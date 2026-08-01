<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\GoogleCalendarClient;
use App\Booking\Services\GoogleCalendarConfigurationService;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Tests\TestCase;

/**
 * The "Test Google Configuration" admin action must actually prove
 * Google Meet works — not just that settings look shaped correctly
 * (that would still report `ready` for a service account with no
 * Workspace Meet entitlement, or one whose domain-wide delegation
 * doesn't cover the requested scope, exactly the bugs this feature
 * fixes). Mirrors ZoomConfigurationServiceTest's fake-client pattern.
 */
class GoogleCalendarConfigurationServiceTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = '116902683368346528512';

    private const CLIENT_EMAIL = 'siri-education@siri-education.iam.gserviceaccount.com';

    private const DELEGATED_ACCOUNT = 'meetings@example.com';

    private function configureGoogle(): void
    {
        $settings = app(MeetingSettings::class);
        $settings->google_meet_enabled = true;
        $settings->google_auth_type = 'service_account';
        $settings->google_calendar_id = 'primary';
        $settings->platform_meeting_account = self::DELEGATED_ACCOUNT;
        $settings->google_credentials_json = Crypt::encryptString(
            json_encode(['type' => 'service_account', 'client_id' => self::CLIENT_ID, 'client_email' => self::CLIENT_EMAIL, 'private_key' => 'FAKE_PRIVATE_KEY_TOKEN_ABCDEFGHIJKLMNOP']),
        );
        $settings->save();
    }

    /** A "healthy" fake — token acquisition always succeeds unless a test overrides it. */
    private function bindFakeClient(): GoogleCalendarClient&Mockery\MockInterface
    {
        $client = Mockery::mock(GoogleCalendarClient::class);
        $client->shouldReceive('requestedScopes')->andReturn(['https://www.googleapis.com/auth/calendar']);
        // byDefault(): overridable by a test-specific expectation below —
        // without it, Mockery may keep matching this stub even after a
        // test defines its own ->andThrow() for the same method.
        $client->shouldReceive('verifyTokenAcquisition')->andReturnNull()->byDefault();
        $this->app->instance(GoogleCalendarClient::class, $client);

        return $client;
    }

    public function test_check_reports_ready_when_live_meet_verification_succeeds(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();

        $client->shouldReceive('allowedConferenceTypes')->once()->andReturn(['hangoutsMeet']);
        $client->shouldReceive('insertEvent')
            ->once()
            ->withArgs(fn (string $c, string $calendarId, array $payload, string $subject, int $conferenceDataVersion, string $sendUpdates): bool => $conferenceDataVersion === 1 && $sendUpdates === 'none')
            ->andReturn(['id' => 'test-evt', 'hangoutLink' => 'https://meet.google.com/test', 'conferenceData' => ['status' => 'success', 'entryPoints' => []]]);
        $client->shouldReceive('deleteEvent')->once()->withArgs(fn (string $c, string $calendarId, string $eventId): bool => $eventId === 'test-evt');

        $service = app(GoogleCalendarConfigurationService::class);

        $this->assertSame('ready', $service->check());
        $this->assertNull($service->lastDiagnostic());

        $diagnostics = $service->lastDiagnostics();
        $this->assertNotNull($diagnostics);
        $this->assertSame(self::CLIENT_ID, $diagnostics->clientId);
        $this->assertSame(self::CLIENT_EMAIL, $diagnostics->clientEmail);
        $this->assertSame(self::DELEGATED_ACCOUNT, $diagnostics->delegatedSubject);
        $this->assertSame(['https://www.googleapis.com/auth/calendar'], $diagnostics->requestedScopes);
        $this->assertSame('primary', $diagnostics->calendarId);
        $this->assertTrue($diagnostics->tokenAcquired);
        $this->assertSame(['hangoutsMeet'], $diagnostics->allowedConferenceTypes);
    }

    public function test_check_reports_invalid_when_token_acquisition_fails_and_never_reaches_calendar_api(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();

        $client->shouldReceive('verifyTokenAcquisition')
            ->once()
            ->andThrow(new \RuntimeException(
                'Google OAuth token error: unauthorized_client. Description: Client is unauthorized to retrieve access tokens using this method, or client not authorized for any of the scopes requested.',
            ));
        $client->shouldNotReceive('allowedConferenceTypes');
        $client->shouldNotReceive('insertEvent');

        $service = app(GoogleCalendarConfigurationService::class);

        $this->assertSame('invalid', $service->check());
        $this->assertStringContainsString('unauthorized_client', (string) $service->lastDiagnostic());

        $diagnostics = $service->lastDiagnostics();
        $this->assertNotNull($diagnostics);
        $this->assertFalse($diagnostics->tokenAcquired);
        $this->assertSame([], $diagnostics->allowedConferenceTypes);
    }

    public function test_check_reports_invalid_when_hangouts_meet_not_in_allowed_types(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();

        $client->shouldReceive('allowedConferenceTypes')->once()->andReturn(['eventHangout']);
        $client->shouldNotReceive('insertEvent');

        $service = app(GoogleCalendarConfigurationService::class);

        $this->assertSame('invalid', $service->check());
        $this->assertStringContainsString('not supported', (string) $service->lastDiagnostic());
        $this->assertStringContainsString('eventHangout', (string) $service->lastDiagnostic());
        $this->assertTrue($service->lastDiagnostics()->tokenAcquired);
    }

    public function test_check_reports_invalid_when_allowed_conference_types_is_empty(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();

        $client->shouldReceive('allowedConferenceTypes')->once()->andReturn([]);
        $client->shouldNotReceive('insertEvent');

        $service = app(GoogleCalendarConfigurationService::class);

        $this->assertSame('invalid', $service->check());
    }

    public function test_check_reports_invalid_and_still_deletes_temporary_event_when_conference_never_confirms(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();

        $client->shouldReceive('allowedConferenceTypes')->once()->andReturn(['hangoutsMeet']);
        $client->shouldReceive('insertEvent')->once()->andReturn([
            'id' => 'test-evt-pending',
            'hangoutLink' => null,
            'conferenceData' => ['status' => 'pending', 'entryPoints' => []],
        ]);
        $client->shouldReceive('getEvent')->atLeast()->once()->andReturn([
            'id' => 'test-evt-pending',
            'hangoutLink' => null,
            'conferenceData' => ['status' => 'pending', 'entryPoints' => []],
        ]);
        $client->shouldReceive('deleteEvent')->once()->withArgs(fn (string $c, string $calendarId, string $eventId): bool => $eventId === 'test-evt-pending');

        $service = app(GoogleCalendarConfigurationService::class);

        $this->assertSame('invalid', $service->check());
        $this->assertNotNull($service->lastDiagnostic());
    }

    public function test_check_retries_bounded_times_then_accepts_success_after_pending(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();

        $client->shouldReceive('allowedConferenceTypes')->once()->andReturn(['hangoutsMeet']);
        $client->shouldReceive('insertEvent')->once()->andReturn([
            'id' => 'test-evt-retry',
            'hangoutLink' => null,
            'conferenceData' => ['status' => 'pending', 'entryPoints' => []],
        ]);
        $client->shouldReceive('getEvent')
            ->once()
            ->andReturn([
                'id' => 'test-evt-retry',
                'hangoutLink' => 'https://meet.google.com/retry-ok',
                'conferenceData' => ['status' => 'success', 'entryPoints' => [['entryPointType' => 'video', 'uri' => 'https://meet.google.com/retry-ok']]],
            ]);
        $client->shouldReceive('deleteEvent')->once();

        $service = app(GoogleCalendarConfigurationService::class);

        $this->assertSame('ready', $service->check());
    }

    public function test_check_deletes_temporary_event_even_when_insert_throws(): void
    {
        $this->configureGoogle();
        $client = $this->bindFakeClient();

        $client->shouldReceive('allowedConferenceTypes')->once()->andReturn(['hangoutsMeet']);
        $client->shouldReceive('insertEvent')->once()->andThrow(new \RuntimeException('Invalid conference type value.'));
        $client->shouldNotReceive('deleteEvent');

        $service = app(GoogleCalendarConfigurationService::class);

        $this->assertSame('invalid', $service->check());
        $this->assertStringContainsString('Test meeting creation failed', (string) $service->lastDiagnostic());
    }

    public function test_check_reports_not_configured_when_meetings_disabled_and_never_touches_client(): void
    {
        $client = $this->bindFakeClient();
        $client->shouldNotReceive('verifyTokenAcquisition');
        $client->shouldNotReceive('allowedConferenceTypes');
        $client->shouldNotReceive('insertEvent');

        $settings = app(MeetingSettings::class);
        $settings->google_meet_enabled = false;
        $settings->save();

        $this->assertSame('not_configured', app(GoogleCalendarConfigurationService::class)->check());
    }

    public function test_check_reports_invalid_when_credential_json_is_missing_client_id(): void
    {
        $settings = app(MeetingSettings::class);
        $settings->google_meet_enabled = true;
        $settings->google_auth_type = 'service_account';
        $settings->google_calendar_id = 'primary';
        $settings->platform_meeting_account = self::DELEGATED_ACCOUNT;
        $settings->google_credentials_json = Crypt::encryptString(
            json_encode(['type' => 'service_account', 'client_email' => self::CLIENT_EMAIL, 'private_key' => 'FAKE_PRIVATE_KEY_TOKEN_ABCDEFGHIJKLMNOP']),
        );
        $settings->save();

        $client = $this->bindFakeClient();
        $client->shouldNotReceive('verifyTokenAcquisition');

        $this->assertSame('invalid', app(GoogleCalendarConfigurationService::class)->check());
    }
}
