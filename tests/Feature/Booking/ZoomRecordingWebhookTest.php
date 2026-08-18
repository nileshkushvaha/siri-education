<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\ZoomMeetingClient;
use App\Booking\Jobs\CaptureLessonRecordingJob;
use App\Booking\Meetings\ZoomMeetingProvider;
use App\Models\Recording;
use App\Models\RecordingProviderEvent;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeZoomMeetingClient;
use Tests\TestCase;

/**
 * The Zoom recording webhook: authenticity, replay safety, and the
 * discipline of doing almost nothing.
 *
 * A webhook handler is the easiest place in an application to get
 * security wrong, so the assertions here are deliberately hostile:
 * unsigned requests, tampered bodies, stale timestamps, replays, and
 * events for lessons this deployment has never heard of.
 *
 * The handler's job is to verify, identify, record and queue — never to
 * download. A test asserts that too, because a recording transfer
 * inside an HTTP request would blow Zoom's three-second acknowledgement
 * budget and trigger endless redelivery.
 */
final class ZoomRecordingWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'zoom-webhook-secret-token';

    private const MEETING_ID = '987654321';

    private FakeZoomMeetingClient $zoom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zoom = new FakeZoomMeetingClient;
        $this->app->instance(ZoomMeetingClient::class, $this->zoom);

        $settings = app(MeetingSettings::class);
        $settings->zoom_enabled = true;
        $settings->zoom_recording_enabled = true;
        $settings->zoom_recording_webhooks_enabled = true;
        $settings->zoom_account_id = 'acct-1';
        $settings->zoom_client_id = 'client-1';
        $settings->zoom_client_secret = Crypt::encryptString('shhh');
        $settings->zoom_webhook_secret = Crypt::encryptString(self::SECRET);
        $settings->zoom_host_user_id = 'host-1';
        $settings->save();
    }

    private function url(string $provider = 'zoom'): string
    {
        return route('api.meetings.recordings.webhook', ['provider' => $provider]);
    }

    /** @param  array<string, mixed>  $payload */
    private function signed(array $payload, ?string $secret = null, ?int $timestampMs = null): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) ($timestampMs ?? (now()->getTimestamp() * 1000));
        $signature = 'v0='.hash_hmac('sha256', sprintf('v0:%s:%s', $timestamp, $body), $secret ?? self::SECRET);

        return [
            'body' => $body,
            'headers' => [
                'x-zm-request-timestamp' => $timestamp,
                'x-zm-signature' => $signature,
                'Content-Type' => 'application/json',
            ],
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function sendWebhook(array $payload, ?string $secret = null, ?int $timestampMs = null)
    {
        ['body' => $body, 'headers' => $headers] = $this->signed($payload, $secret, $timestampMs);

        return $this->call('POST', $this->url(), [], [], [], $this->serverHeaders($headers), $body);
    }

    /**
     * @param  array<string, string>  $headers
     *
     * Content-Type goes in unprefixed: Symfony reads CONTENT_TYPE, not
     * HTTP_CONTENT_TYPE, and without it the raw JSON body is never
     * parsed into request input.
     */
    private function serverHeaders(array $headers): array
    {
        $server = [];

        foreach ($headers as $key => $value) {
            $normalized = strtoupper(str_replace('-', '_', $key));
            $server[$normalized === 'CONTENT_TYPE' ? $normalized : 'HTTP_'.$normalized] = $value;
        }

        return $server;
    }

    /** @return array<string, mixed> */
    private function recordingCompletedPayload(string $meetingId = self::MEETING_ID, int $eventTs = 1700000000000): array
    {
        return [
            'event' => 'recording.completed',
            'event_ts' => $eventTs,
            // Zoom really does include a short-lived download token here.
            // Nothing in SIRI may persist it — see the security test.
            'download_token' => 'SHORT-LIVED-DOWNLOAD-TOKEN',
            'payload' => [
                'object' => [
                    'id' => $meetingId,
                    'uuid' => 'uuid-'.$meetingId,
                    'topic' => 'Lesson',
                    'recording_files' => [
                        ['id' => 'video-1', 'file_type' => 'MP4', 'download_url' => 'https://zoom.us/rec/download/video-1'],
                    ],
                ],
            ],
        ];
    }

    private function zoomLesson(): Recording
    {
        $recording = Recording::factory()->create(['provider' => ZoomMeetingProvider::KEY]);
        $recording->bookingMeeting->update([
            'provider' => ZoomMeetingProvider::KEY,
            'provider_meeting_id' => self::MEETING_ID,
            'ends_at' => now()->subHour(),
        ]);

        return $recording->fresh();
    }

    // ── Authenticity ──────────────────────────────────────────────────

    public function test_a_correctly_signed_webhook_is_accepted(): void
    {
        Queue::fake();
        $this->zoomLesson();

        $this->sendWebhook($this->recordingCompletedPayload())
            ->assertOk()
            ->assertJson(['status' => 'accepted']);
    }

    public function test_an_unsigned_webhook_is_rejected(): void
    {
        $this->postJson($this->url(), $this->recordingCompletedPayload())->assertStatus(401);
    }

    public function test_a_webhook_signed_with_the_wrong_secret_is_rejected(): void
    {
        $this->sendWebhook($this->recordingCompletedPayload(), secret: 'not-the-secret')->assertStatus(401);
    }

    /**
     * The signature covers the RAW body, so altering even one byte
     * after signing must fail — this is what stops a captured webhook
     * being replayed against a different lesson.
     */
    public function test_a_tampered_body_is_rejected(): void
    {
        ['body' => $body, 'headers' => $headers] = $this->signed($this->recordingCompletedPayload());
        $tampered = str_replace(self::MEETING_ID, '111111111', $body);

        $this->call('POST', $this->url(), [], [], [], $this->serverHeaders($headers), $tampered)
            ->assertStatus(401);
    }

    public function test_a_stale_timestamp_is_rejected(): void
    {
        $this->sendWebhook(
            $this->recordingCompletedPayload(),
            timestampMs: now()->subHours(2)->getTimestamp() * 1000,
        )->assertStatus(401);
    }

    /** Fail closed: with no secret configured, nothing is trusted. */
    public function test_webhooks_are_refused_when_no_secret_is_configured(): void
    {
        $settings = app(MeetingSettings::class);
        $settings->zoom_webhook_secret = null;
        $settings->save();

        $this->sendWebhook($this->recordingCompletedPayload())->assertNotFound();
    }

    public function test_webhooks_are_refused_when_the_switch_is_off(): void
    {
        $settings = app(MeetingSettings::class);
        $settings->zoom_recording_webhooks_enabled = false;
        $settings->save();

        $this->sendWebhook($this->recordingCompletedPayload())->assertNotFound();
    }

    public function test_an_unknown_provider_is_not_found(): void
    {
        $this->postJson(route('api.meetings.recordings.webhook', ['provider' => 'nope']), [])->assertNotFound();
    }

    /** Google Meet has no recording webhook, so the endpoint must not pretend it does. */
    public function test_a_provider_without_webhook_support_is_not_found(): void
    {
        $this->postJson(route('api.meetings.recordings.webhook', ['provider' => 'google_meet']), [])->assertNotFound();
    }

    // ── Endpoint ownership challenge ──────────────────────────────────

    /**
     * Zoom will not deliver events until the endpoint proves it holds
     * the secret, by echoing a token alongside its HMAC.
     */
    public function test_the_url_validation_challenge_is_answered_correctly(): void
    {
        $plainToken = 'qgg8vlvZRS6UYooatFL8Aw';

        $response = $this->sendWebhook([
            'event' => 'endpoint.url_validation',
            'event_ts' => 1700000000000,
            'payload' => ['plainToken' => $plainToken],
        ]);

        $response->assertOk()->assertJson([
            'plainToken' => $plainToken,
            'encryptedToken' => hash_hmac('sha256', $plainToken, self::SECRET),
        ]);

        // A challenge is not an event and must not be logged as one.
        $this->assertSame(0, RecordingProviderEvent::query()->count());
    }

    // ── Replay safety ─────────────────────────────────────────────────

    public function test_a_replayed_webhook_is_acknowledged_but_not_reprocessed(): void
    {
        Queue::fake();
        $this->zoomLesson();
        $payload = $this->recordingCompletedPayload();

        $this->sendWebhook($payload)->assertOk()->assertJson(['status' => 'accepted']);
        $this->sendWebhook($payload)->assertOk()->assertJson(['status' => 'duplicate']);
        $this->sendWebhook($payload)->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, RecordingProviderEvent::query()->count());
        Queue::assertPushed(CaptureLessonRecordingJob::class, 1);
    }

    /** Two genuinely different deliveries are two events, not a false duplicate. */
    public function test_a_distinct_event_is_not_treated_as_a_replay(): void
    {
        Queue::fake();
        $this->zoomLesson();

        $this->sendWebhook($this->recordingCompletedPayload(eventTs: 1700000000000))->assertJson(['status' => 'accepted']);
        $this->sendWebhook($this->recordingCompletedPayload(eventTs: 1700000999000))->assertJson(['status' => 'accepted']);

        $this->assertSame(2, RecordingProviderEvent::query()->count());
    }

    // ── Handler discipline ────────────────────────────────────────────

    /** The transfer belongs on the queue — never inside the request. */
    public function test_the_webhook_queues_the_transfer_and_downloads_nothing(): void
    {
        Queue::fake();
        $recording = $this->zoomLesson();

        $this->sendWebhook($this->recordingCompletedPayload())->assertOk();

        Queue::assertPushed(
            CaptureLessonRecordingJob::class,
            fn (CaptureLessonRecordingJob $job): bool => $job->recordingId === $recording->getKey(),
        );
        $this->assertNotContains('openRecordingStream', $this->zoom->calls);
        $this->assertNotContains('listMeetingRecordings', $this->zoom->calls);
    }

    /** Zoom emits recording events SIRI cannot act on; acknowledge and drop. */
    public function test_an_irrelevant_recording_event_is_acknowledged_without_queueing(): void
    {
        Queue::fake();
        $this->zoomLesson();

        $payload = $this->recordingCompletedPayload();
        $payload['event'] = 'recording.started';

        $this->sendWebhook($payload)->assertOk();

        Queue::assertNotPushed(CaptureLessonRecordingJob::class);
        $this->assertSame('ignored_event', RecordingProviderEvent::query()->first()->processing_status);
    }

    /** A webhook for a meeting this deployment never created is not an error. */
    public function test_an_unknown_meeting_is_acknowledged_without_queueing(): void
    {
        Queue::fake();

        $this->sendWebhook($this->recordingCompletedPayload(meetingId: '000000000'))->assertOk();

        Queue::assertNotPushed(CaptureLessonRecordingJob::class);
        $this->assertSame('unknown_meeting', RecordingProviderEvent::query()->first()->processing_status);
    }

    public function test_a_malformed_payload_is_rejected_with_a_generic_message(): void
    {
        $response = $this->sendWebhook(['event' => 'recording.completed', 'event_ts' => 1, 'payload' => ['object' => []]]);

        $response->assertStatus(422)->assertJson(['message' => 'Malformed webhook payload.']);
    }

    // ── Security ──────────────────────────────────────────────────────

    /**
     * Zoom's payload carries a short-lived download token and signed
     * download URLs. None of it may be persisted: the operational
     * record exists to answer "have we seen this event?", not to
     * archive credentials.
     */
    public function test_no_download_token_or_url_is_persisted_from_the_webhook(): void
    {
        Queue::fake();
        $this->zoomLesson();

        $this->sendWebhook($this->recordingCompletedPayload())->assertOk();

        $stored = json_encode(RecordingProviderEvent::query()->first()->toArray());
        $this->assertStringNotContainsString('SHORT-LIVED-DOWNLOAD-TOKEN', $stored);
        $this->assertStringNotContainsString('zoom.us/rec/download', $stored);
        $this->assertStringNotContainsString('recording_files', $stored);
    }
}
