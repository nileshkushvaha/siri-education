<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\GoogleCalendarClient;
use App\Booking\Contracts\GoogleMeetClient;
use App\Booking\DTOs\MeetingCreationContext;
use App\Booking\Enums\MeetingStatus;
use App\Booking\Exceptions\GatewayRequestException;
use App\Booking\Meetings\GoogleCalendarMeetProvider;
use App\Models\Booking;
use App\Settings\FeatureSettings;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Tests\Support\FakeGoogleMeetClient;
use Tests\TestCase;

/**
 * Product decision (2026-09-05): lessons are always recorded, and
 * nobody should have to press Record. Meet only allows automatic
 * recording on spaces created through the Meet API, so a
 * recording-eligible lesson gets its space from the Meet API (auto
 * recording ON) and the Calendar event is attached to it. Everything
 * downstream — meeting code, discovery, the Drive copy — is unchanged.
 *
 * The safety property matters as much as the feature: the space is an
 * optimisation of HOW recording starts, never a condition for the
 * meeting to exist. Any failure creating or attaching it falls back to
 * the Calendar-created conference the platform has always used.
 */
final class GoogleMeetAutoRecordingTest extends TestCase
{
    use RefreshDatabase;

    private const DELEGATED_ACCOUNT = 'meetings@example.test';

    private FakeGoogleMeetClient $meet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->meet = new FakeGoogleMeetClient;
        $this->app->instance(GoogleMeetClient::class, $this->meet);

        $settings = app(MeetingSettings::class);
        $settings->google_meet_enabled = true;
        $settings->google_auth_type = 'service_account';
        $settings->google_calendar_id = 'calendar-123@group.calendar.google.com';
        $settings->platform_meeting_account = self::DELEGATED_ACCOUNT;
        $settings->google_credentials_json = Crypt::encryptString(json_encode(['type' => 'service_account', 'client_email' => 'svc@project.iam.gserviceaccount.com', 'private_key' => 'FAKE_PRIVATE_KEY_TOKEN']));
        $settings->save();
    }

    private function enableRecording(bool $platform = true, bool $provider = true): void
    {
        $features = app(FeatureSettings::class);
        $features->recording_enabled = $platform;
        $features->save();

        $settings = app(MeetingSettings::class);
        $settings->recording_enabled = $platform;
        $settings->google_meet_recording_enabled = $provider;
        $settings->save();
    }

    private function calendar(): GoogleCalendarClient&Mockery\MockInterface
    {
        $client = Mockery::mock(GoogleCalendarClient::class);
        $client->shouldReceive('allowedConferenceTypes')->andReturn(['hangoutsMeet']);
        $this->app->instance(GoogleCalendarClient::class, $client);

        return $client;
    }

    private function booking(): Booking
    {
        return Booking::factory()->confirmed()->paid()->create();
    }

    public function test_a_recording_eligible_lesson_gets_a_meet_api_space_with_auto_recording_attached_to_the_event(): void
    {
        $this->enableRecording();
        $calendar = $this->calendar();
        $booking = $this->booking();

        $calendar->shouldReceive('insertEvent')
            ->once()
            ->withArgs(function (string $credentials, string $calendarId, array $payload, ?string $subject): bool {
                return ($payload['attachConference']['meetingCode'] ?? null) === 'auto-rec-spce'
                    && ($payload['attachConference']['meetingUri'] ?? null) === 'https://meet.google.com/auto-rec-spce'
                    && ! array_key_exists('conferenceRequestId', $payload)
                    && $subject === self::DELEGATED_ACCOUNT;
            })
            ->andReturn(['id' => 'evt-1', 'hangoutLink' => null, 'conferenceData' => ['conferenceId' => 'auto-rec-spce', 'entryPoints' => [['entryPointType' => 'video', 'uri' => 'https://meet.google.com/auto-rec-spce']]]]);

        $result = app(GoogleCalendarMeetProvider::class)->createMeeting($booking, new MeetingCreationContext);

        $this->assertSame([['autoRecording' => true, 'meetingCode' => 'auto-rec-spce']], $this->meet->spacesCreated);
        $this->assertSame(MeetingStatus::Created, $result->status);
        $this->assertSame('auto-rec-spce', $result->providerMeetingId, 'the space code is what discovery later matches on');
        $this->assertSame('https://meet.google.com/auto-rec-spce', $result->joinUrl);
        $this->assertSame('evt-1', $result->providerEventId);
        $this->assertTrue($result->metadata['auto_recording']);
    }

    /** With recording off (platform or provider), the meeting is the Calendar-created conference it has always been. */
    public function test_a_lesson_that_will_not_be_recorded_keeps_the_calendar_created_conference(): void
    {
        foreach ([[false, true], [true, false]] as [$platform, $provider]) {
            $this->enableRecording($platform, $provider);
            $calendar = $this->calendar();
            $booking = $this->booking();

            $calendar->shouldReceive('insertEvent')
                ->once()
                ->withArgs(fn (string $c, string $id, array $payload): bool => isset($payload['conferenceRequestId']) && ! isset($payload['attachConference']))
                ->andReturn(['id' => 'evt-2', 'hangoutLink' => null, 'conferenceData' => ['conferenceId' => 'cal-made-conf', 'status' => 'success', 'entryPoints' => [['entryPointType' => 'video', 'uri' => 'https://meet.google.com/cal-made-conf']]]]);

            $result = app(GoogleCalendarMeetProvider::class)->createMeeting($booking, new MeetingCreationContext);

            $this->assertSame([], $this->meet->spacesCreated, 'no Meet API space without recording');
            $this->assertSame('cal-made-conf', $result->providerMeetingId);
            $this->assertArrayNotHasKey('auto_recording', $result->metadata);
        }
    }

    /**
     * The settings scope not yet granted, the Meet API down, a quota —
     * none of it may cost a lesson its meeting. The Calendar-created
     * conference is used and the lesson is recorded manually as before.
     */
    public function test_a_space_creation_failure_falls_back_to_a_calendar_created_conference(): void
    {
        $this->enableRecording();
        $this->meet->throwOnCreateSpace = new GatewayRequestException('Google Meet OAuth token error: unauthorized_client');
        $calendar = $this->calendar();
        $booking = $this->booking();

        $calendar->shouldReceive('insertEvent')
            ->once()
            ->withArgs(fn (string $c, string $id, array $payload): bool => isset($payload['conferenceRequestId']) && ! isset($payload['attachConference']))
            ->andReturn(['id' => 'evt-3', 'hangoutLink' => null, 'conferenceData' => ['conferenceId' => 'cal-made-conf', 'status' => 'success', 'entryPoints' => [['entryPointType' => 'video', 'uri' => 'https://meet.google.com/cal-made-conf']]]]);

        $result = app(GoogleCalendarMeetProvider::class)->createMeeting($booking, new MeetingCreationContext);

        $this->assertSame(MeetingStatus::Created, $result->status);
        $this->assertSame('cal-made-conf', $result->providerMeetingId);
    }

    /** Calendar refusing the attached space is not fatal either: the space still records, and the link rides on the event's location. */
    public function test_a_refused_attachment_retries_with_the_link_as_the_event_location(): void
    {
        $this->enableRecording();
        $calendar = $this->calendar();
        $booking = $this->booking();

        $calendar->shouldReceive('insertEvent')
            ->once()
            ->withArgs(fn (string $c, string $id, array $payload): bool => isset($payload['attachConference']))
            ->andThrow(new GatewayRequestException('Invalid conference type value.'));

        $calendar->shouldReceive('insertEvent')
            ->once()
            ->withArgs(fn (string $c, string $id, array $payload): bool => ! isset($payload['attachConference'])
                && ! isset($payload['conferenceRequestId'])
                && ($payload['location'] ?? null) === 'https://meet.google.com/auto-rec-spce')
            ->andReturn(['id' => 'evt-4', 'hangoutLink' => null, 'conferenceData' => []]);

        $result = app(GoogleCalendarMeetProvider::class)->createMeeting($booking, new MeetingCreationContext);

        $this->assertSame(MeetingStatus::Created, $result->status);
        $this->assertSame('auto-rec-spce', $result->providerMeetingId);
        $this->assertSame('https://meet.google.com/auto-rec-spce', $result->joinUrl);
        $this->assertSame('evt-4', $result->providerEventId);
    }

    /** The space is created with auto-recording ON and nothing else — no other space configuration is ever sent. */
    public function test_the_space_is_requested_with_auto_recording_on(): void
    {
        $this->enableRecording();
        $calendar = $this->calendar();
        $calendar->shouldReceive('insertEvent')->andReturn(['id' => 'evt-5', 'hangoutLink' => null, 'conferenceData' => []]);

        app(GoogleCalendarMeetProvider::class)->createMeeting($this->booking(), new MeetingCreationContext);

        $this->assertTrue($this->meet->spacesCreated[0]['autoRecording']);
        $this->assertSame(['createSpace'], $this->meet->calls);
    }
}
