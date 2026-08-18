<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\GoogleDriveClient;
use App\Booking\Contracts\GoogleMeetClient;
use App\Booking\Enums\RecordingFailureCode;
use App\Booking\Enums\RecordingStatus;
use App\Booking\Exceptions\GatewayRequestException;
use App\Booking\Gateways\GoogleMeetSdkClient;
use App\Booking\Meetings\GoogleCalendarMeetProvider;
use App\Booking\Services\RecordingService;
use App\Booking\Services\RecordingStagingArea;
use App\Booking\Storage\FilesystemRecordingStorage;
use App\Booking\Storage\GoogleDriveRecordingStorage;
use App\Models\OperationalAlert;
use App\Models\Recording;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeGoogleDriveClient;
use Tests\Support\FakeGoogleMeetClient;
use Tests\TestCase;

/**
 * Google Meet recording acquisition, end to end, against fake Meet and
 * Drive APIs.
 *
 * The single most important property here is MAPPING: one SIRI lesson
 * must never receive another lesson's video. Google's model makes that
 * a real risk — a meeting space can host many conferences over its
 * lifetime, and one conference can produce several recordings — so the
 * mapping tests below are deliberately adversarial.
 *
 * The second property is that a recording already sitting in Google
 * Drive is not dragged through this server just to be put back into
 * Google Drive.
 */
final class GoogleMeetRecordingAcquisitionTest extends TestCase
{
    use RefreshDatabase;

    private const MEETING_CODE = 'abc-defg-hjk';

    private FakeGoogleMeetClient $meet;

    private FakeGoogleDriveClient $drive;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->meet = new FakeGoogleMeetClient;
        $this->drive = new FakeGoogleDriveClient;
        $this->app->instance(GoogleMeetClient::class, $this->meet);
        $this->app->instance(GoogleDriveClient::class, $this->drive);

        $settings = app(MeetingSettings::class);
        $settings->google_meet_recording_enabled = true;
        $settings->platform_meeting_account = 'classes@example.test';
        $settings->google_credentials_json = Crypt::encryptString('{"client_email":"svc@example.iam.gserviceaccount.com","client_id":"1"}');
        $settings->recording_drive_root_folder_id = 'root-folder-id';
        $settings->recording_drive_shared_drive_id = null;
        $settings->save();

        config(['recordings.storage_driver' => GoogleDriveRecordingStorage::KEY]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app(RecordingStagingArea::class)->path());

        parent::tearDown();
    }

    private function provider(): GoogleCalendarMeetProvider
    {
        return app(GoogleCalendarMeetProvider::class);
    }

    /** A finished lesson whose Meet conference is identified by the persisted meeting code. */
    private function lesson(?string $meetingCode = self::MEETING_CODE): Recording
    {
        $recording = Recording::factory()->create(['provider' => GoogleCalendarMeetProvider::KEY]);

        $recording->bookingMeeting->update([
            'provider' => GoogleCalendarMeetProvider::KEY,
            'provider_meeting_id' => $meetingCode,
            'join_url' => $meetingCode !== null ? 'https://meet.google.com/'.$meetingCode : null,
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
        ]);

        return $recording->fresh();
    }

    private function capture(Recording $recording): void
    {
        app(RecordingService::class)->capture($recording, $this->provider());
    }

    // ── Capability declaration ────────────────────────────────────────

    public function test_the_meet_provider_now_advertises_recording_support_when_configured(): void
    {
        $this->assertTrue($this->provider()->supportsRecording());
    }

    /**
     * Ships off until an administrator has added the Meet and Drive
     * scopes to the domain-wide delegation grant — turning it on before
     * that would fail every lesson with a permission error.
     */
    public function test_recording_support_is_declined_while_the_meet_acquisition_switch_is_off(): void
    {
        $settings = app(MeetingSettings::class);
        $settings->google_meet_recording_enabled = false;
        $settings->save();

        $this->assertFalse($this->provider()->supportsRecording());
    }

    public function test_recording_support_is_declined_without_google_credentials(): void
    {
        $settings = app(MeetingSettings::class);
        $settings->google_credentials_json = null;
        $settings->save();

        $this->assertFalse($this->provider()->supportsRecording());
    }

    /** Capability checks run on hot paths and must never call Google. */
    public function test_declaring_recording_support_makes_no_api_calls(): void
    {
        $this->provider()->supportsRecording();

        $this->assertSame([], $this->meet->calls);
    }

    // ── Mapping: lesson → conference → recording ──────────────────────

    public function test_a_lesson_resolves_its_conference_by_meeting_code_and_time_window(): void
    {
        $recording = $this->lesson();
        $this->meet
            ->withConference(self::MEETING_CODE, 'conferenceRecords/conf-1', now()->subHours(2)->toIso8601ZuluString('millisecond'))
            ->withRecording('conferenceRecords/conf-1', 'conferenceRecords/conf-1/recordings/rec-1', 'FILE_GENERATED', 'meet-drive-file-1');

        $discovered = $this->provider()->discoverRecording($recording->bookingMeeting);

        $this->assertNotNull($discovered);
        $this->assertSame('conferenceRecords/conf-1/recordings/rec-1', $discovered->providerReference);
        $this->assertSame('meet-drive-file-1', $discovered->nativeSource?->reference);

        // Queried by the immutable meeting code, never by title or name.
        $this->assertSame(self::MEETING_CODE, $this->meet->conferenceQueries[0]['meetingCode']);
    }

    /**
     * THE critical mapping test. A meeting space is reusable, so the
     * same code can carry a different class on a different day. Only the
     * time window separates them — and it must.
     */
    public function test_a_conference_outside_the_lessons_window_can_never_attach_to_it(): void
    {
        $recording = $this->lesson();

        // A conference in the same space, but a week earlier: a
        // different lesson entirely.
        $this->meet
            ->withConference(self::MEETING_CODE, 'conferenceRecords/other-lesson', now()->subDays(7)->toIso8601ZuluString('millisecond'))
            ->withRecording('conferenceRecords/other-lesson', 'conferenceRecords/other-lesson/recordings/rec-x', 'FILE_GENERATED', 'someone-elses-video');

        $discovered = $this->provider()->discoverRecording($recording->bookingMeeting);

        $this->assertNull($discovered, 'a conference from another lesson in the same space must not be adopted');
    }

    public function test_a_conference_from_a_different_meeting_space_is_never_returned(): void
    {
        $recording = $this->lesson();

        $this->meet
            ->withConference('zzz-zzzz-zzz', 'conferenceRecords/foreign', now()->subHours(2)->toIso8601ZuluString('millisecond'))
            ->withRecording('conferenceRecords/foreign', 'conferenceRecords/foreign/recordings/rec-y', 'FILE_GENERATED', 'foreign-video');

        $this->assertNull($this->provider()->discoverRecording($recording->bookingMeeting));
    }

    /** Recurring lessons reuse a space; each occurrence must pick up only its own conference. */
    public function test_recurring_lessons_in_one_space_each_resolve_their_own_conference(): void
    {
        $today = $this->lesson();

        $this->meet
            ->withConference(self::MEETING_CODE, 'conferenceRecords/last-week', now()->subDays(7)->toIso8601ZuluString('millisecond'))
            ->withRecording('conferenceRecords/last-week', 'conferenceRecords/last-week/recordings/old', 'FILE_GENERATED', 'last-weeks-video')
            ->withConference(self::MEETING_CODE, 'conferenceRecords/today', now()->subHours(2)->toIso8601ZuluString('millisecond'))
            ->withRecording('conferenceRecords/today', 'conferenceRecords/today/recordings/new', 'FILE_GENERATED', 'todays-video');

        $discovered = $this->provider()->discoverRecording($today->bookingMeeting);

        $this->assertSame('todays-video', $discovered?->nativeSource?->reference);
    }

    public function test_a_meeting_with_no_usable_meet_identifier_fails_permanently(): void
    {
        $recording = $this->lesson(meetingCode: null);
        $recording->bookingMeeting->update(['provider_meeting_id' => null, 'join_url' => null]);

        $this->capture($recording->fresh());

        $this->assertSame(RecordingFailureCode::ProviderCapabilityMissing, $recording->fresh()->failure_code);
    }

    // ── Asynchronous generation ───────────────────────────────────────

    /**
     * A class ending does not mean the file exists. Google reports
     * STARTED/ENDED while it is still producing the MP4, and that must
     * stay retryable — treating it as a failure would throw away most
     * recordings.
     */
    public function test_a_recording_still_generating_leaves_the_lesson_retryable(): void
    {
        $recording = $this->lesson();
        $this->meet
            ->withConference(self::MEETING_CODE, 'conferenceRecords/conf-1', now()->subHours(2)->toIso8601ZuluString('millisecond'))
            ->withRecording('conferenceRecords/conf-1', 'conferenceRecords/conf-1/recordings/rec-1', 'ENDED', null);

        $this->assertNull($this->provider()->discoverRecording($recording->bookingMeeting));

        $this->capture($recording);
        $recording->refresh();

        $this->assertSame(RecordingStatus::Pending, $recording->status);
        $this->assertNull($recording->failure_code);
    }

    public function test_no_conference_record_yet_leaves_the_lesson_retryable(): void
    {
        $recording = $this->lesson();

        $this->capture($recording);
        $recording->refresh();

        $this->assertSame(RecordingStatus::Pending, $recording->status);
        $this->assertNull($recording->failure_code);
    }

    /** Once generated, a later sweep picks it up — eventual correctness. */
    public function test_a_recording_generated_after_an_earlier_attempt_is_eventually_ingested(): void
    {
        $recording = $this->lesson();
        $this->meet->withConference(self::MEETING_CODE, 'conferenceRecords/conf-1', now()->subHours(2)->toIso8601ZuluString('millisecond'));

        $this->capture($recording);
        $this->assertSame(RecordingStatus::Pending, $recording->fresh()->status);

        // Google finishes generating the file.
        $this->meet->withRecording('conferenceRecords/conf-1', 'conferenceRecords/conf-1/recordings/rec-1', 'FILE_GENERATED', 'meet-drive-file-1');

        $this->capture($recording->fresh());

        $this->assertSame(RecordingStatus::Available, $recording->fresh()->status);
    }

    // ── Drive-native ingestion ────────────────────────────────────────

    /**
     * The whole point of the native path: the recording already lives
     * in Drive, so it is copied server-side and no bytes pass through
     * this host.
     */
    public function test_a_meet_recording_is_copied_inside_drive_without_being_downloaded(): void
    {
        $recording = $this->lesson();
        $this->drive->sourceSizes['meet-drive-file-1'] = 987_654;
        $this->meet
            ->withConference(self::MEETING_CODE, 'conferenceRecords/conf-1', now()->subHours(2)->toIso8601ZuluString('millisecond'))
            ->withRecording('conferenceRecords/conf-1', 'conferenceRecords/conf-1/recordings/rec-1', 'FILE_GENERATED', 'meet-drive-file-1');

        $this->capture($recording);
        $recording->refresh();

        $this->assertSame(RecordingStatus::Available, $recording->status);
        $this->assertContains('copyFile', $this->drive->calls);
        $this->assertNotContains('openReadStream', $this->drive->calls, 'the recording must not be downloaded to this server');
        $this->assertNotContains('uploadResumable', $this->drive->calls, 'the recording must not be re-uploaded');

        // Copied from the right source, into SIRI's own partitioned area.
        $this->assertSame('meet-drive-file-1', $this->drive->copies[0]['source']);
        $this->assertStringStartsWith('folder:', $this->drive->copies[0]['parent']);

        // Size comes from what Drive says about the COPY, not from the
        // provider's claim about the source.
        $this->assertSame(987_654, $recording->size_bytes);
    }

    /** COPY, never move: Google's own artifact stays exactly where Meet put it. */
    public function test_the_original_meet_artifact_is_never_moved_or_deleted(): void
    {
        $recording = $this->lesson();
        $this->meet
            ->withConference(self::MEETING_CODE, 'conferenceRecords/conf-1', now()->subHours(2)->toIso8601ZuluString('millisecond'))
            ->withRecording('conferenceRecords/conf-1', 'conferenceRecords/conf-1/recordings/rec-1', 'FILE_GENERATED', 'meet-drive-file-1');

        $this->capture($recording);

        $this->assertNotContains('meet-drive-file-1', $this->drive->deleted);
        $this->assertNotContains('deleteFile', $this->drive->calls);
        // The recording SIRI serves is the copy, not Google's original.
        $this->assertNotSame('meet-drive-file-1', $recording->fresh()->storage_path);
    }

    public function test_the_stored_copy_is_named_from_the_booking_reference_only(): void
    {
        $recording = $this->lesson();
        $this->meet
            ->withConference(self::MEETING_CODE, 'conferenceRecords/conf-1', now()->subHours(2)->toIso8601ZuluString('millisecond'))
            ->withRecording('conferenceRecords/conf-1', 'conferenceRecords/conf-1/recordings/rec-1', 'FILE_GENERATED', 'meet-drive-file-1');

        $this->capture($recording);

        $name = $this->drive->copies[0]['name'];
        $this->assertStringContainsString($recording->booking->reference, $name);
        $this->assertStringNotContainsString($recording->student->email, $name);
        $this->assertStringNotContainsString($recording->student->name, $name);
    }

    /** A repeated ingestion must never leave a second copy in Drive. */
    public function test_repeated_capture_does_not_produce_a_second_drive_copy(): void
    {
        $recording = $this->lesson();
        $this->meet
            ->withConference(self::MEETING_CODE, 'conferenceRecords/conf-1', now()->subHours(2)->toIso8601ZuluString('millisecond'))
            ->withRecording('conferenceRecords/conf-1', 'conferenceRecords/conf-1/recordings/rec-1', 'FILE_GENERATED', 'meet-drive-file-1');

        $this->capture($recording);
        $this->capture($recording->fresh());
        $this->capture($recording->fresh());

        $this->assertCount(1, $this->drive->copies);
        $this->assertSame(1, Recording::query()->count());
    }

    /** Verification still gates Available — a copy is not trusted just because the API returned. */
    public function test_a_copy_that_fails_verification_never_becomes_available(): void
    {
        $recording = $this->lesson();
        $this->meet
            ->withConference(self::MEETING_CODE, 'conferenceRecords/conf-1', now()->subHours(2)->toIso8601ZuluString('millisecond'))
            ->withRecording('conferenceRecords/conf-1', 'conferenceRecords/conf-1/recordings/rec-1', 'FILE_GENERATED', 'meet-drive-file-1');

        $this->capture($recording);
        $recording->refresh();

        // Drive loses the copy between storing and a later re-check.
        $this->drive->files = [];
        $recording->forceFill(['status' => RecordingStatus::Stored])->save();

        $this->capture($recording->fresh());

        $this->assertNotSame(RecordingStatus::Available, $recording->fresh()->status);
    }

    // ── Streaming fallback ────────────────────────────────────────────

    /**
     * If Drive refuses the server-side copy, ingestion must not fail —
     * it degrades to streaming within the same attempt.
     */
    public function test_a_refused_drive_copy_falls_back_to_streamed_ingestion(): void
    {
        $recording = $this->lesson();
        $this->drive->throwOnCopy = new GatewayRequestException('Google Drive API error (HTTP 400, reason: badRequest): cannot copy.');
        $this->drive->downloadBytes = "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 200);
        $this->meet
            ->withConference(self::MEETING_CODE, 'conferenceRecords/conf-1', now()->subHours(2)->toIso8601ZuluString('millisecond'))
            ->withRecording('conferenceRecords/conf-1', 'conferenceRecords/conf-1/recordings/rec-1', 'FILE_GENERATED', 'meet-drive-file-1');

        $this->capture($recording);
        $recording->refresh();

        $this->assertSame(RecordingStatus::Available, $recording->status);
        $this->assertContains('openReadStream', $this->drive->calls, 'fallback must download the artifact');
        $this->assertContains('uploadResumable', $this->drive->calls, 'fallback must upload it to SIRI storage');
    }

    /** The fallback download streams to disk and leaves nothing staged behind. */
    public function test_the_streaming_fallback_cleans_up_its_staged_file(): void
    {
        $recording = $this->lesson();
        $this->drive->throwOnCopy = new GatewayRequestException('Google Drive API error (HTTP 400, reason: badRequest): cannot copy.');
        $this->drive->downloadBytes = "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 200);
        $this->meet
            ->withConference(self::MEETING_CODE, 'conferenceRecords/conf-1', now()->subHours(2)->toIso8601ZuluString('millisecond'))
            ->withRecording('conferenceRecords/conf-1', 'conferenceRecords/conf-1/recordings/rec-1', 'FILE_GENERATED', 'meet-drive-file-1');

        $this->capture($recording);

        $this->assertCount(0, File::files(app(RecordingStagingArea::class)->path()));
    }

    /**
     * The S3 rehearsal. With storage on a filesystem disk, the Drive
     * source is no longer "already in the destination", so the native
     * copy cannot apply and ingestion streams instead — end to end,
     * with no change to any domain code.
     */
    public function test_a_non_drive_storage_backend_streams_the_meet_recording_instead(): void
    {
        Storage::fake('local');
        config([
            'recordings.storage_driver' => FilesystemRecordingStorage::KEY,
            'recordings.filesystem.disk' => 'local',
        ]);

        $recording = $this->lesson();
        $this->drive->downloadBytes = "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 200);
        $this->meet
            ->withConference(self::MEETING_CODE, 'conferenceRecords/conf-1', now()->subHours(2)->toIso8601ZuluString('millisecond'))
            ->withRecording('conferenceRecords/conf-1', 'conferenceRecords/conf-1/recordings/rec-1', 'FILE_GENERATED', 'meet-drive-file-1');

        $this->capture($recording);
        $recording->refresh();

        $this->assertSame(RecordingStatus::Available, $recording->status);
        $this->assertSame(FilesystemRecordingStorage::KEY, $recording->storage_driver);
        $this->assertCount(0, $this->drive->copies, 'a non-Drive backend must not attempt a Drive-side copy');
        $this->assertContains('openReadStream', $this->drive->calls);
        Storage::disk('local')->assertExists($recording->storage_path);
    }

    // ── Consent and auto-recording ────────────────────────────────────

    /**
     * Consent gates registration, which is upstream of everything here:
     * a lesson without both participants' consent never gets a
     * Recording row, so acquisition is never even attempted.
     */
    public function test_a_lesson_without_consent_never_reaches_acquisition(): void
    {
        $recording = $this->lesson();
        $booking = $recording->booking;
        $booking->student->profile->update(['consents_to_recording' => false]);

        $registered = app(RecordingService::class)->registerIfEligible(
            $booking->fresh(),
            $recording->bookingMeeting,
            $this->provider(),
        );

        $this->assertNull($registered);
        $this->assertSame([], $this->meet->calls);
    }

    /**
     * SIRI never turns Meet's automatic recording on. It cannot — the
     * space is created by the Calendar API, and Meet only lets an app
     * configure spaces it created itself — and it deliberately does not
     * try: an auto-recording switch that outran the consent gates would
     * record classes nobody agreed to record. Asserted structurally so
     * a future change has to confront the consent question explicitly.
     */
    public function test_the_integration_never_configures_automatic_recording(): void
    {
        $sources = php_strip_whitespace(app_path('Booking/Gateways/GoogleMeetSdkClient.php'))
            .php_strip_whitespace(app_path('Booking/Services/GoogleMeetRecordingLocator.php'))
            .php_strip_whitespace(app_path('Booking/Meetings/GoogleCalendarMeetProvider.php'));

        foreach (['autoRecordingGeneration', 'ArtifactConfig', 'RecordingConfig', 'spaces->patch', 'spaces->create'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sources);
        }
    }

    // ── Multiple recording sessions ───────────────────────────────────

    /**
     * Google returns one artifact per Record start/stop. SIRI stores one
     * recording per lesson, so the rule must be deterministic —
     * earliest wins — rather than "whatever the API listed first".
     */
    public function test_multiple_recording_sessions_deterministically_select_the_earliest(): void
    {
        $recording = $this->lesson();
        $this->meet
            ->withConference(self::MEETING_CODE, 'conferenceRecords/conf-1', now()->subHours(2)->toIso8601ZuluString('millisecond'))
            // Deliberately listed out of order.
            ->withRecording('conferenceRecords/conf-1', 'conferenceRecords/conf-1/recordings/second', 'FILE_GENERATED', 'second-half', '2026-08-18T11:00:00Z')
            ->withRecording('conferenceRecords/conf-1', 'conferenceRecords/conf-1/recordings/first', 'FILE_GENERATED', 'first-half', '2026-08-18T10:00:00Z');

        $discovered = $this->provider()->discoverRecording($recording->bookingMeeting);

        $this->assertSame('first-half', $discovered?->nativeSource?->reference);
        $this->assertSame(2, $discovered?->artifactCount);
    }

    /** Extra segments are never dropped in silence — an operator is told. */
    public function test_multiple_recording_sessions_raise_an_operational_alert(): void
    {
        $recording = $this->lesson();
        $this->meet
            ->withConference(self::MEETING_CODE, 'conferenceRecords/conf-1', now()->subHours(2)->toIso8601ZuluString('millisecond'))
            ->withRecording('conferenceRecords/conf-1', 'conferenceRecords/conf-1/recordings/a', 'FILE_GENERATED', 'part-a', '2026-08-18T10:00:00Z')
            ->withRecording('conferenceRecords/conf-1', 'conferenceRecords/conf-1/recordings/b', 'FILE_GENERATED', 'part-b', '2026-08-18T11:00:00Z');

        $this->capture($recording);

        $alert = OperationalAlert::query()->where('type', 'recording_multiple_artifacts')->first();
        $this->assertNotNull($alert, 'extra recording segments must be reported, never silently discarded');
        $this->assertSame(2, $alert->metadata['artifact_count']);
        $this->assertSame(RecordingStatus::Available, $recording->fresh()->status);
    }

    public function test_a_single_recording_raises_no_multiple_artifact_alert(): void
    {
        $recording = $this->lesson();
        $this->meet
            ->withConference(self::MEETING_CODE, 'conferenceRecords/conf-1', now()->subHours(2)->toIso8601ZuluString('millisecond'))
            ->withRecording('conferenceRecords/conf-1', 'conferenceRecords/conf-1/recordings/only', 'FILE_GENERATED', 'the-video');

        $this->capture($recording);

        $this->assertSame(0, OperationalAlert::query()->where('type', 'recording_multiple_artifacts')->count());
    }

    // ── Failure classification ────────────────────────────────────────

    /**
     * A missing OAuth scope in the delegation grant is an operator fix,
     * so it must stay retryable — a permanent failure would discard
     * every recording made before someone noticed.
     */
    public function test_a_meet_permission_error_is_transient_not_permanent(): void
    {
        $recording = $this->lesson();
        $this->meet->throwOnConferenceList = new GatewayRequestException(
            'Google Meet API error (HTTP 403, reason: PERMISSION_DENIED): caller lacks permission.'
        );

        $this->capture($recording);
        $recording->refresh();

        $this->assertSame(RecordingStatus::Pending, $recording->status);
        $this->assertFalse(RecordingFailureCode::SourceAccessDenied->isPermanent());
    }

    public function test_a_meet_rate_limit_is_transient(): void
    {
        $recording = $this->lesson();
        $this->meet->throwOnConferenceList = new GatewayRequestException(
            'Google Meet API error (HTTP 429, reason: RESOURCE_EXHAUSTED): rate limit exceeded.'
        );

        $this->capture($recording);

        $this->assertSame(RecordingStatus::Pending, $recording->fresh()->status);
        $this->assertFalse(RecordingFailureCode::SourceRateLimited->isPermanent());
    }

    /** An expired conference record can never come back, so retrying is pointless. */
    public function test_an_expired_conference_record_fails_permanently(): void
    {
        $recording = $this->lesson();
        $this->meet->throwOnConferenceList = new GatewayRequestException(
            'Google Meet API error (HTTP 404, reason: NOT_FOUND): conference record not found.'
        );

        $this->capture($recording);
        $recording->refresh();

        $this->assertSame(RecordingStatus::Failed, $recording->status);
        $this->assertSame(RecordingFailureCode::SourceExpired, $recording->failure_code);
    }

    // ── Security ──────────────────────────────────────────────────────

    /**
     * The Meet artifact's Drive id is Google's, not SIRI's. It must not
     * end up on the recording row, where it would be an out-of-band
     * pointer to a file outside SIRI's retention control.
     */
    public function test_the_google_source_file_id_is_never_persisted_on_the_recording(): void
    {
        $recording = $this->lesson();
        $this->meet
            ->withConference(self::MEETING_CODE, 'conferenceRecords/conf-1', now()->subHours(2)->toIso8601ZuluString('millisecond'))
            ->withRecording('conferenceRecords/conf-1', 'conferenceRecords/conf-1/recordings/rec-1', 'FILE_GENERATED', 'meet-drive-file-1');

        $this->capture($recording);
        $recording->refresh();

        $this->assertNotSame('meet-drive-file-1', $recording->storage_path);
        $this->assertStringNotContainsString('meet-drive-file-1', json_encode($recording->toArray()));
        // provider_reference is the Meet RESOURCE name — an API
        // identifier, not a Drive artifact pointer.
        $this->assertStringStartsWith('conferenceRecords/', $recording->provider_reference);
    }

    /** The Meet exportUri is a user-facing Drive link and must never be read or stored. */
    public function test_the_meet_export_uri_is_never_used(): void
    {
        $sources = php_strip_whitespace(app_path('Booking/Gateways/GoogleMeetSdkClient.php'))
            .php_strip_whitespace(app_path('Booking/Services/GoogleMeetRecordingLocator.php'));

        $this->assertStringNotContainsString('getExportUri', $sources);
        $this->assertStringNotContainsString('exportUri', $sources);
    }

    /** Meet's read-only scope, and nothing wider. */
    public function test_the_meet_integration_requests_only_the_readonly_space_scope(): void
    {
        $this->assertSame(
            ['https://www.googleapis.com/auth/meetings.space.readonly'],
            app(GoogleMeetSdkClient::class)->requestedScopes(),
        );
    }

    /** Discovery must never touch Drive — it is a metadata lookup only. */
    public function test_discovery_alone_touches_no_drive_file(): void
    {
        $recording = $this->lesson();
        $this->meet
            ->withConference(self::MEETING_CODE, 'conferenceRecords/conf-1', now()->subHours(2)->toIso8601ZuluString('millisecond'))
            ->withRecording('conferenceRecords/conf-1', 'conferenceRecords/conf-1/recordings/rec-1', 'FILE_GENERATED', 'meet-drive-file-1');

        $this->provider()->discoverRecording($recording->bookingMeeting);

        $this->assertSame([], $this->drive->calls);
    }

    // ── Reconciliation ────────────────────────────────────────────────

    /** The bounded sweep is what recovers a recording no job managed to fetch. */
    public function test_the_reconciliation_sweep_discovers_and_ingests_a_missed_recording(): void
    {
        $recording = $this->lesson();
        $this->meet
            ->withConference(self::MEETING_CODE, 'conferenceRecords/conf-1', now()->subHours(2)->toIso8601ZuluString('millisecond'))
            ->withRecording('conferenceRecords/conf-1', 'conferenceRecords/conf-1/recordings/rec-1', 'FILE_GENERATED', 'meet-drive-file-1');

        $this->artisan('recordings:capture')->assertSuccessful();

        $this->assertSame(RecordingStatus::Available, $recording->fresh()->status);
        $this->assertCount(1, $this->drive->copies);
    }

    /** Every Meet query is bounded by the lesson's own window — never an unbounded scan. */
    public function test_every_conference_query_is_bounded_by_an_explicit_time_window(): void
    {
        $meeting = $this->lesson()->bookingMeeting;

        $this->provider()->discoverRecording($meeting);

        $query = $this->meet->conferenceQueries[0];
        $this->assertNotSame('', $query['from']);
        $this->assertNotSame('', $query['to']);
        $this->assertTrue($query['from'] < $query['to']);
        $this->assertTrue(
            $query['from'] >= $meeting->starts_at->subDay()->toIso8601ZuluString('millisecond'),
            'the window must stay tight around the lesson, not open-ended',
        );
    }
}
