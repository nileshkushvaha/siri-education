<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Contracts\GoogleCalendarClient;
use App\Booking\Contracts\GoogleMeetClient;
use App\Booking\DTOs\MeetingCreationContext;
use App\Booking\Enums\MeetingStatus;
use App\Booking\Exceptions\GatewayRequestException;
use App\Booking\Meetings\GoogleCalendarMeetProvider;
use App\Models\Booking;
use App\Models\User;
use App\Settings\FeatureSettings;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Tests\Support\FakeGoogleMeetClient;
use Tests\TestCase;

/**
 * The instructor as Google Meet co-host: added on the lesson's Meet-API
 * space when the feature is on, with the profile's Meet account or the
 * login email, never fatal for the meeting, and surfaced to
 * administrators when it fails. Off by default: nothing changes.
 */
final class GoogleMeetCoHostTest extends TestCase
{
    use RefreshDatabase;

    private const DELEGATED_ACCOUNT = 'meetings@example.com';

    private FakeGoogleMeetClient $meet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->meet = new FakeGoogleMeetClient;
        $this->app->instance(GoogleMeetClient::class, $this->meet);

        $settings = app(MeetingSettings::class);
        $settings->meetings_enabled = true;
        $settings->google_meet_enabled = true;
        $settings->google_auth_type = 'service_account';
        $settings->google_calendar_id = 'calendar-123@group.calendar.google.com';
        $settings->platform_meeting_account = self::DELEGATED_ACCOUNT;
        $settings->google_credentials_json = Crypt::encryptString(json_encode(['type' => 'service_account', 'client_email' => 'svc@project.iam.gserviceaccount.com', 'private_key' => 'FAKE_PRIVATE_KEY_TOKEN']));
        $settings->save();
    }

    private function enableCoHost(bool $on = true): void
    {
        $settings = app(MeetingSettings::class);
        $settings->google_meet_cohost_enabled = $on;
        $settings->save();
    }

    private function enableRecording(): void
    {
        $features = app(FeatureSettings::class);
        $features->recording_enabled = true;
        $features->save();

        $settings = app(MeetingSettings::class);
        $settings->recording_enabled = true;
        $settings->google_meet_recording_enabled = true;
        $settings->save();
    }

    private function calendar(): GoogleCalendarClient&Mockery\MockInterface
    {
        $client = Mockery::mock(GoogleCalendarClient::class);
        $client->shouldReceive('allowedConferenceTypes')->andReturn(['hangoutsMeet']);
        $client->shouldReceive('insertEvent')->andReturn(['id' => 'evt-1', 'hangoutLink' => null, 'conferenceData' => ['conferenceId' => 'auto-rec-spce', 'entryPoints' => [['entryPointType' => 'video', 'uri' => 'https://meet.google.com/auto-rec-spce']]]])->byDefault();
        $this->app->instance(GoogleCalendarClient::class, $client);

        return $client;
    }

    private function booking(?string $meetAccount = null): Booking
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email' => 'Teacher@Example.test']);
        // Through the model, so UserProfile::googleMeetAccount() normalises it as the forms do.
        $instructor->profile->fill(['google_meet_account' => $meetAccount])->save();

        return Booking::factory()->confirmed()->paid()->create(['instructor_id' => $instructor->id]);
    }

    public function test_the_feature_ships_off(): void
    {
        $this->assertFalse(app(MeetingSettings::class)->google_meet_cohost_enabled);
        $this->assertStringContainsString(
            "add('meeting.google_meet_cohost_enabled', false)",
            (string) file_get_contents(base_path('database/settings/2026_11_27_100000_add_google_meet_cohost_setting.php')),
        );
    }

    public function test_with_the_feature_off_no_space_and_no_co_host_are_created_for_an_unrecorded_lesson(): void
    {
        $this->calendar();

        $result = app(GoogleCalendarMeetProvider::class)->createMeeting($this->booking(), new MeetingCreationContext);

        $this->assertSame(MeetingStatus::Created, $result->status);
        $this->assertSame([], $this->meet->spacesCreated);
        $this->assertSame([], $this->meet->coHostsAdded);
        $this->assertArrayNotHasKey('cohost', $result->metadata);
    }

    public function test_the_instructor_is_added_as_co_host_with_their_meet_account(): void
    {
        $this->enableCoHost();
        $this->calendar();

        $result = app(GoogleCalendarMeetProvider::class)->createMeeting($this->booking('Teach.Meet@Example.test'), new MeetingCreationContext);

        $this->assertSame(MeetingStatus::Created, $result->status);
        $this->assertCount(1, $this->meet->spacesCreated, 'a Meet-API space is created for the co-host even without recording');
        $this->assertFalse($this->meet->spacesCreated[0]['autoRecording'], 'co-host alone never switches recording on');
        $this->assertSame([['space' => 'spaces/fake-auto-rec-spce', 'email' => 'teach.meet@example.test']], $this->meet->coHostsAdded);
        $this->assertSame(['status' => GoogleCalendarMeetProvider::COHOST_ADDED], $result->metadata['cohost']);
        $this->assertFalse($result->metadata['auto_recording']);
    }

    public function test_the_login_email_is_used_when_no_meet_account_is_set(): void
    {
        $this->enableCoHost();
        $this->calendar();

        app(GoogleCalendarMeetProvider::class)->createMeeting($this->booking(), new MeetingCreationContext);

        $this->assertSame('teacher@example.test', $this->meet->coHostsAdded[0]['email']);
    }

    public function test_a_recorded_lesson_gets_both_auto_recording_and_the_co_host_on_one_space(): void
    {
        $this->enableCoHost();
        $this->enableRecording();
        $this->calendar();

        $result = app(GoogleCalendarMeetProvider::class)->createMeeting($this->booking(), new MeetingCreationContext);

        $this->assertCount(1, $this->meet->spacesCreated);
        $this->assertTrue($this->meet->spacesCreated[0]['autoRecording']);
        $this->assertCount(1, $this->meet->coHostsAdded);
        $this->assertTrue($result->metadata['auto_recording']);
        $this->assertSame(GoogleCalendarMeetProvider::COHOST_ADDED, $result->metadata['cohost']['status']);
    }

    public function test_a_co_host_failure_never_costs_the_lesson_its_meeting_and_is_audited_for_admins(): void
    {
        $this->enableCoHost();
        $this->calendar();
        $this->meet->throwOnAddCoHost = new GatewayRequestException('Google Meet refused the co-host request: member not allowed (HTTP 403).');
        $booking = $this->booking();

        $meeting = app(BookingMeetingServiceInterface::class)->createMeeting($booking, GoogleCalendarMeetProvider::KEY);

        $this->assertSame(MeetingStatus::Created, $meeting?->status);
        $this->assertSame('https://meet.google.com/auto-rec-spce', $meeting->join_url);
        $this->assertSame(GoogleCalendarMeetProvider::COHOST_FAILED, $meeting->metadata['cohost']['status']);
        $this->assertStringContainsString('HTTP 403', $meeting->metadata['cohost']['reason']);
        $this->assertDatabaseHas('activity_log', ['event' => 'meeting_cohost_failed', 'subject_id' => $booking->id]);
        $this->assertDatabaseMissing('activity_log', ['event' => 'meeting_creation_failed', 'subject_id' => $booking->id]);
    }

    public function test_a_successful_co_host_writes_no_failure_audit(): void
    {
        $this->enableCoHost();
        $this->calendar();
        $booking = $this->booking();

        $meeting = app(BookingMeetingServiceInterface::class)->createMeeting($booking, GoogleCalendarMeetProvider::KEY);

        $this->assertSame(GoogleCalendarMeetProvider::COHOST_ADDED, $meeting?->metadata['cohost']['status']);
        $this->assertDatabaseMissing('activity_log', ['event' => 'meeting_cohost_failed', 'subject_id' => $booking->id]);
    }

    public function test_a_space_creation_failure_still_yields_a_calendar_conference_without_a_co_host(): void
    {
        $this->enableCoHost();
        $this->calendar();
        $this->meet->throwOnCreateSpace = new GatewayRequestException('Meet API unavailable');

        $result = app(GoogleCalendarMeetProvider::class)->createMeeting($this->booking(), new MeetingCreationContext);

        $this->assertSame(MeetingStatus::Created, $result->status);
        $this->assertSame([], $this->meet->coHostsAdded, 'members only exist on app-created spaces');
        $this->assertArrayNotHasKey('cohost', $result->metadata);
    }

    public function test_an_unusable_meet_account_skips_the_co_host_instead_of_failing(): void
    {
        $this->enableCoHost();
        $this->calendar();
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email' => 'not-an-email']);
        $instructor->profile()->update(['google_meet_account' => 'also not an email']);
        $booking = Booking::factory()->confirmed()->paid()->create(['instructor_id' => $instructor->id]);

        $result = app(GoogleCalendarMeetProvider::class)->createMeeting($booking, new MeetingCreationContext);

        $this->assertSame(MeetingStatus::Created, $result->status);
        $this->assertSame([], $this->meet->coHostsAdded);
        $this->assertSame([], $this->meet->spacesCreated, 'no reason to create a space for an unrecorded lesson without a usable co-host');
    }
}
