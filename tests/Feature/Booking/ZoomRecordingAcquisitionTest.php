<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\ZoomMeetingClient;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\RecordingFailureCode;
use App\Booking\Enums\RecordingStatus;
use App\Booking\Exceptions\GatewayRequestException;
use App\Booking\Gateways\ZoomApiClient;
use App\Booking\Meetings\ZoomMeetingProvider;
use App\Booking\Services\RecordingService;
use App\Booking\Services\RecordingStagingArea;
use App\Models\Recording;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeZoomMeetingClient;
use Tests\Support\InMemoryRecordingStorage;
use Tests\TestCase;

/**
 * Zoom cloud-recording acquisition, end to end, against a fake Zoom API
 * and a fake storage backend.
 *
 * Two properties matter most here, and both are places where a naive
 * implementation quietly does the wrong thing:
 *
 *  1. ARTIFACT SELECTION. Zoom returns a mixture of files for one
 *     meeting — several MP4 layouts, an audio-only M4A, a chat log.
 *     Taking element zero eventually stores a chat transcript as the
 *     lesson video.
 *  2. NO SPECIAL PATH. A Zoom recording flows through the same
 *     ingestion service, the same staging area and the same
 *     RecordingStorage as a Google Meet one. Nothing in this file
 *     touches a Zoom-specific storage class, because none exists.
 */
final class ZoomRecordingAcquisitionTest extends TestCase
{
    use RefreshDatabase;

    private const MEETING_ID = '987654321';

    private FakeZoomMeetingClient $zoom;

    private InMemoryRecordingStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->zoom = new FakeZoomMeetingClient;
        $this->app->instance(ZoomMeetingClient::class, $this->zoom);

        $this->storage = new InMemoryRecordingStorage;
        $this->app->instance(InMemoryRecordingStorage::class, $this->storage);
        config([
            'recordings.storage_driver' => InMemoryRecordingStorage::KEY,
            'recordings.drivers' => [InMemoryRecordingStorage::KEY => InMemoryRecordingStorage::class],
        ]);

        $settings = app(MeetingSettings::class);
        $settings->zoom_enabled = true;
        $settings->zoom_recording_enabled = true;
        $settings->zoom_account_id = 'acct-1';
        $settings->zoom_client_id = 'client-1';
        $settings->zoom_client_secret = Crypt::encryptString('shhh');
        $settings->zoom_host_user_id = 'host-1';
        $settings->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app(RecordingStagingArea::class)->path());

        parent::tearDown();
    }

    private function provider(): ZoomMeetingProvider
    {
        return app(ZoomMeetingProvider::class);
    }

    private function lesson(): Recording
    {
        $recording = Recording::factory()->create(['provider' => ZoomMeetingProvider::KEY]);
        // The sweep only looks at confirmed/completed bookings.
        $recording->booking->update(['status' => BookingStatus::Confirmed]);
        $recording->bookingMeeting->update([
            'provider' => ZoomMeetingProvider::KEY,
            'provider_meeting_id' => self::MEETING_ID,
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
        ]);

        return $recording->fresh();
    }

    private function capture(Recording $recording): void
    {
        app(RecordingService::class)->capture($recording, $this->provider());
    }

    private function mp4Bytes(): string
    {
        return "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 200);
    }

    // ── Capability declaration ────────────────────────────────────────

    public function test_zoom_advertises_recording_support_when_configured(): void
    {
        $this->assertTrue($this->provider()->supportsRecording());
    }

    /** Provider enabled, recording OFF — a valid and independently expressible combination. */
    public function test_zoom_declines_recording_when_only_the_recording_switch_is_off(): void
    {
        $settings = app(MeetingSettings::class);
        $settings->zoom_recording_enabled = false;
        $settings->save();

        $this->assertFalse($this->provider()->supportsRecording());
        // …while the provider itself remains perfectly usable.
        $this->assertTrue($this->provider()->isConfigured());
    }

    public function test_zoom_declines_recording_without_credentials(): void
    {
        $settings = app(MeetingSettings::class);
        $settings->zoom_client_secret = null;
        $settings->save();

        $this->assertFalse($this->provider()->supportsRecording());
    }

    /** Capability checks sit on hot paths and must never call Zoom. */
    public function test_declaring_recording_support_makes_no_api_calls(): void
    {
        $this->provider()->supportsRecording();

        $this->assertSame([], $this->zoom->calls);
    }

    // ── Artifact selection ────────────────────────────────────────────

    /**
     * THE selection test. Zoom's response deliberately leads with an
     * audio file and a chat log — taking element zero would store one
     * of those as the class recording.
     */
    public function test_the_class_video_is_selected_out_of_a_mixed_file_list(): void
    {
        $recording = $this->lesson();
        $this->zoom
            ->withRecordingFile(self::MEETING_ID, 'audio-1', 'M4A', 'audio_only')
            ->withRecordingFile(self::MEETING_ID, 'chat-1', 'CHAT', 'chat_file')
            ->withRecordingFile(self::MEETING_ID, 'video-1', 'MP4', 'shared_screen_with_speaker_view');

        $discovered = $this->provider()->discoverRecording($recording->bookingMeeting);

        $this->assertSame('video-1', $discovered?->providerReference);
        $this->assertSame('video/mp4', $discovered?->mimeType);
    }

    /** Several legitimate video layouts resolve by documented preference, not by array order. */
    public function test_multiple_video_layouts_resolve_by_configured_preference(): void
    {
        $recording = $this->lesson();
        // Listed worst-first on purpose.
        $this->zoom
            ->withRecordingFile(self::MEETING_ID, 'speaker', 'MP4', 'speaker_view')
            ->withRecordingFile(self::MEETING_ID, 'gallery', 'MP4', 'gallery_view')
            ->withRecordingFile(self::MEETING_ID, 'shared-speaker', 'MP4', 'shared_screen_with_speaker_view');

        $discovered = $this->provider()->discoverRecording($recording->bookingMeeting);

        $this->assertSame('shared-speaker', $discovered?->providerReference);
        $this->assertSame(3, $discovered?->artifactCount);
    }

    /** An unfamiliar future layout is least-preferred, never discarded. */
    public function test_an_unknown_layout_is_still_usable_when_it_is_the_only_video(): void
    {
        $recording = $this->lesson();
        $this->zoom->withRecordingFile(self::MEETING_ID, 'novel', 'MP4', 'some_future_layout');

        $this->assertSame('novel', $this->provider()->discoverRecording($recording->bookingMeeting)?->providerReference);
    }

    public function test_a_file_still_processing_is_not_selected(): void
    {
        $recording = $this->lesson();
        $this->zoom->withRecordingFile(self::MEETING_ID, 'video-1', 'MP4', 'shared_screen_with_speaker_view', status: 'processing');

        $this->assertNull($this->provider()->discoverRecording($recording->bookingMeeting));
    }

    /** Audio-only meetings must not masquerade as a class video. */
    public function test_an_audio_only_meeting_yields_no_recording(): void
    {
        $recording = $this->lesson();
        $this->zoom->withRecordingFile(self::MEETING_ID, 'audio-1', 'M4A', 'audio_only');

        $this->assertNull($this->provider()->discoverRecording($recording->bookingMeeting));
    }

    // ── Asynchronous availability ─────────────────────────────────────

    public function test_no_recording_yet_leaves_the_lesson_retryable(): void
    {
        $recording = $this->lesson();
        $this->zoom->meetingsWithoutRecordings = [self::MEETING_ID];

        $this->capture($recording);
        $recording->refresh();

        $this->assertSame(RecordingStatus::Pending, $recording->status);
        $this->assertNull($recording->failure_code);
    }

    public function test_a_recording_that_appears_later_is_eventually_ingested(): void
    {
        $recording = $this->lesson();
        $this->zoom->meetingsWithoutRecordings = [self::MEETING_ID];

        $this->capture($recording);
        $this->assertSame(RecordingStatus::Pending, $recording->fresh()->status);

        // Zoom finishes processing.
        $this->zoom->meetingsWithoutRecordings = [];
        $this->zoom->downloadBytes = $this->mp4Bytes();
        $this->zoom->withRecordingFile(self::MEETING_ID, 'video-1', 'MP4', 'shared_screen_with_speaker_view');

        $this->capture($recording->fresh());

        $this->assertSame(RecordingStatus::Available, $recording->fresh()->status);
    }

    // ── Ingestion through the shared pipeline ─────────────────────────

    public function test_a_zoom_recording_is_streamed_and_stored_through_the_shared_pipeline(): void
    {
        $recording = $this->lesson();
        $this->zoom->downloadBytes = $this->mp4Bytes();
        $this->zoom->withRecordingFile(self::MEETING_ID, 'video-1', 'MP4', 'shared_screen_with_speaker_view', size: 4096);

        $this->capture($recording);
        $recording->refresh();

        $this->assertSame(RecordingStatus::Available, $recording->status);
        $this->assertSame('video-1', $recording->provider_reference);
        $this->assertSame(InMemoryRecordingStorage::KEY, $recording->storage_driver);
        $this->assertCount(1, $this->storage->objects);
        // Byte-identical: no truncation, no re-encode.
        $this->assertSame($this->mp4Bytes(), array_values($this->storage->objects)[0]['bytes']);
    }

    public function test_the_stored_object_is_named_from_the_booking_reference_only(): void
    {
        $recording = $this->lesson();
        $this->zoom->downloadBytes = $this->mp4Bytes();
        $this->zoom->withRecordingFile(self::MEETING_ID, 'video-1', 'MP4', 'shared_screen_with_speaker_view');

        $this->capture($recording);
        $recording->refresh();

        $name = $this->storage->storedName($recording->storage_path);
        $this->assertStringContainsString($recording->booking->reference, $name);
        $this->assertStringNotContainsString($recording->student->email, $name);
        $this->assertStringNotContainsString($recording->student->name, $name);
    }

    public function test_a_successful_transfer_leaves_no_staged_file_behind(): void
    {
        $recording = $this->lesson();
        $this->zoom->downloadBytes = $this->mp4Bytes();
        $this->zoom->withRecordingFile(self::MEETING_ID, 'video-1', 'MP4', 'shared_screen_with_speaker_view');

        $this->capture($recording);

        $this->assertCount(0, File::files(app(RecordingStagingArea::class)->path()));
    }

    public function test_a_failed_transfer_also_leaves_no_staged_file_behind(): void
    {
        $recording = $this->lesson();
        $this->zoom->throwOnDownload = new GatewayRequestException('Zoom API failed to download the recording (HTTP 500).');
        $this->zoom->withRecordingFile(self::MEETING_ID, 'video-1', 'MP4', 'shared_screen_with_speaker_view');

        $this->capture($recording);

        $this->assertCount(0, File::files(app(RecordingStagingArea::class)->path()));
    }

    // ── Failure classification ────────────────────────────────────────

    public function test_a_zoom_rate_limit_is_transient(): void
    {
        $recording = $this->lesson();
        $this->zoom->throwOnRecordings = new GatewayRequestException('Zoom API failed to list meeting recordings (HTTP 429): rate limit.');

        $this->capture($recording);

        $this->assertSame(RecordingStatus::Pending, $recording->fresh()->status);
        $this->assertFalse(RecordingFailureCode::SourceRateLimited->isPermanent());
    }

    public function test_expired_zoom_cloud_storage_fails_permanently(): void
    {
        $recording = $this->lesson();
        $this->zoom->throwOnRecordings = new GatewayRequestException('Zoom API failed to list meeting recordings (HTTP 410): recording expired.');

        $this->capture($recording);
        $recording->refresh();

        $this->assertSame(RecordingStatus::Failed, $recording->status);
        $this->assertSame(RecordingFailureCode::SourceExpired, $recording->failure_code);
    }

    public function test_a_meeting_without_a_zoom_meeting_id_fails_permanently(): void
    {
        $recording = $this->lesson();
        $recording->bookingMeeting->update(['provider_meeting_id' => null]);

        $this->capture($recording->fresh());

        $this->assertSame(RecordingFailureCode::ProviderCapabilityMissing, $recording->fresh()->failure_code);
    }

    // ── Idempotency ───────────────────────────────────────────────────

    public function test_repeated_capture_produces_one_recording_and_one_stored_object(): void
    {
        $recording = $this->lesson();
        $this->zoom->downloadBytes = $this->mp4Bytes();
        $this->zoom->withRecordingFile(self::MEETING_ID, 'video-1', 'MP4', 'shared_screen_with_speaker_view');

        $this->capture($recording);
        $this->capture($recording->fresh());
        $this->artisan('recordings:capture')->assertSuccessful();

        $this->assertSame(1, Recording::query()->count());
        $this->assertCount(1, $this->storage->objects);
        $this->assertCount(1, $this->zoom->downloads, 'the recording must be downloaded exactly once');
    }

    /** Reconciliation is the guarantee — a lesson whose webhook never arrived still ingests. */
    public function test_the_reconciliation_sweep_ingests_a_recording_no_webhook_announced(): void
    {
        $recording = $this->lesson();
        $this->zoom->downloadBytes = $this->mp4Bytes();
        $this->zoom->withRecordingFile(self::MEETING_ID, 'video-1', 'MP4', 'shared_screen_with_speaker_view');

        $this->artisan('recordings:capture')->assertSuccessful();

        $this->assertSame(RecordingStatus::Available, $recording->fresh()->status);
    }

    // ── Security ──────────────────────────────────────────────────────

    /** The download URL is a provider credential surface and must never be persisted. */
    public function test_the_zoom_download_url_is_never_persisted_on_the_recording(): void
    {
        $recording = $this->lesson();
        $this->zoom->downloadBytes = $this->mp4Bytes();
        $this->zoom->withRecordingFile(
            self::MEETING_ID,
            'video-1',
            'MP4',
            'shared_screen_with_speaker_view',
            downloadUrl: 'https://zoom.us/rec/download/SECRET-HANDLE',
        );

        $this->capture($recording);
        $recording->refresh();

        $serialized = json_encode($recording->toArray());
        $this->assertStringNotContainsString('SECRET-HANDLE', $serialized);
        $this->assertStringNotContainsString('zoom.us', $serialized);
        $this->assertNotSame('https://zoom.us/rec/download/SECRET-HANDLE', $recording->storage_path);
    }

    /** No arbitrary URL fetching: only Zoom-hosted download locations are ever opened. */
    public function test_a_non_zoom_download_host_is_refused_by_the_gateway(): void
    {
        $client = app(ZoomApiClient::class);

        $this->expectException(GatewayRequestException::class);
        $this->expectExceptionMessage('non-Zoom host');

        $client->openRecordingStream('https://evil.example.com/steal', 'token');
    }
}
