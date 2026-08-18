<?php

declare(strict_types=1);

namespace App\Booking\Meetings;

use App\Booking\Contracts\DiscoversRecordingArtifacts;
use App\Booking\Contracts\MeetingProviderInterface;
use App\Booking\Contracts\MeetingRecordingProviderInterface;
use App\Booking\Contracts\RecordingWebhookProvider;
use App\Booking\Contracts\ZoomMeetingClient;
use App\Booking\DTOs\DiscoveredRecording;
use App\Booking\DTOs\MeetingCancellationResult;
use App\Booking\DTOs\MeetingCreationContext;
use App\Booking\DTOs\MeetingCreationResult;
use App\Booking\DTOs\MeetingUpdateContext;
use App\Booking\DTOs\ProviderRecordingResult;
use App\Booking\DTOs\RecordingWebhookResult;
use App\Booking\DTOs\StagedRecordingFile;
use App\Booking\Enums\MeetingStatus;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\InvalidRecordingWebhookException;
use App\Booking\Meetings\Concerns\BuildsSafeMeetingContent;
use App\Booking\Meetings\Concerns\SanitizesProviderMessages;
use App\Booking\Meetings\Concerns\VerifiesZoomWebhooks;
use App\Booking\Services\RecordingEligibilityResolver;
use App\Booking\Services\ZoomRecordingLocator;
use App\Booking\Services\ZoomRecordingStager;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Settings\MeetingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

/**
 * Schedules a Zoom meeting (Server-to-Server OAuth, platform-owned
 * host account) for a confirmed booking. All Zoom HTTP traffic lives
 * behind ZoomMeetingClient — this class never sees a token or a raw
 * API response, only the client's sanitized six-field array.
 *
 * The meeting is created under MeetingSettings::zoom_host_user_id (or
 * zoom_host_email) — the platform's Zoom user, never an instructor's
 * personal account. start_url is persisted as BookingMeeting.host_url
 * (hidden from serialization, never shown to students) because it
 * grants host privileges on sight; the join password rides the same
 * hidden password column the other providers use.
 *
 * RECORDING. This provider also supplies lesson recordings via Zoom
 * cloud recording, on exactly the same contracts Google Meet uses —
 * see ZoomRecordingLocator for artifact selection and
 * ZoomRecordingStager for the streamed download. Two independent
 * switches govern it and neither implies the other:
 *
 *   zoom_enabled            may SIRI create Zoom meetings at all
 *   zoom_recording_enabled  may Zoom meetings enter the recording flow
 *
 * auto_recording is set per meeting from the full SIRI eligibility
 * chain, so a lesson SIRI considers non-recordable is created with
 * recording off at the provider — consent is enforced at Zoom, not
 * merely after the fact.
 */
final class ZoomMeetingProvider implements DiscoversRecordingArtifacts, MeetingProviderInterface, MeetingRecordingProviderInterface, RecordingWebhookProvider
{
    use BuildsSafeMeetingContent;
    use SanitizesProviderMessages;
    use VerifiesZoomWebhooks;

    public const string KEY = 'zoom';

    /** Zoom meeting type 2 = scheduled (never an instant meeting). */
    private const int TYPE_SCHEDULED = 2;

    /**
     * The one Zoom event that means "a cloud recording is finished and
     * fetchable". Verified against Zoom's current webhook reference
     * rather than recalled — every other recording event describes a
     * transition we cannot yet ingest.
     */
    private const string EVENT_RECORDING_COMPLETED = 'recording.completed';

    public function __construct(
        private readonly ZoomMeetingClient $client,
        private readonly MeetingSettings $settings,
        private readonly RecordingEligibilityResolver $recordingEligibility,
        private readonly ZoomRecordingLocator $recordings,
        private readonly ZoomRecordingStager $stager,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function isConfigured(): bool
    {
        return $this->settings->zoom_enabled
            && filled($this->settings->zoom_account_id)
            && filled($this->settings->zoom_client_id)
            && $this->settings->decryptedZoomClientSecret() !== null
            && (filled($this->settings->zoom_host_user_id) || filled($this->settings->zoom_host_email));
    }

    public function createMeeting(Booking $booking, MeetingCreationContext $context): MeetingCreationResult
    {
        $startsAt = $context->startsAt ?? $booking->starts_at;
        $endsAt = $context->endsAt ?? $booking->ends_at;
        $timezone = $context->timezone ?? $this->timezoneFor($booking);

        try {
            $meeting = $this->client->createMeeting(
                $this->hostUser(),
                $this->meetingPayload($booking, $startsAt, $endsAt, $timezone),
            );
        } catch (Throwable $e) {
            throw new BookingException($this->sanitize($e->getMessage()));
        }

        return $this->resultFromMeeting($startsAt, $endsAt, $timezone, $meeting);
    }

    public function updateMeeting(BookingMeeting $meeting, MeetingUpdateContext $context): MeetingCreationResult
    {
        $booking = $meeting->booking;
        $startsAt = $context->startsAt ?? $meeting->starts_at;
        $endsAt = $context->endsAt ?? $meeting->ends_at;
        $timezone = $context->timezone ?? $meeting->timezone;

        try {
            $fresh = $meeting->provider_meeting_id !== null
                ? $this->client->updateMeeting($meeting->provider_meeting_id, $this->meetingPayload($booking, $startsAt, $endsAt, $timezone))
                : $this->client->createMeeting($this->hostUser(), $this->meetingPayload($booking, $startsAt, $endsAt, $timezone));
        } catch (Throwable $e) {
            throw new BookingException($this->sanitize($e->getMessage()));
        }

        return $this->resultFromMeeting($startsAt, $endsAt, $timezone, $fresh);
    }

    public function cancelMeeting(BookingMeeting $meeting): MeetingCancellationResult
    {
        if ($meeting->provider_meeting_id === null) {
            return new MeetingCancellationResult(status: MeetingStatus::Cancelled);
        }

        try {
            $this->client->deleteMeeting($meeting->provider_meeting_id);
        } catch (Throwable $e) {
            return new MeetingCancellationResult(status: MeetingStatus::Failed, failureReason: $this->sanitize($e->getMessage()));
        }

        return new MeetingCancellationResult(status: MeetingStatus::Cancelled);
    }

    // ── RecordingWebhookProvider ──────────────────────────────────────

    public function supportsRecordingWebhooks(): bool
    {
        return $this->settings->zoom_recording_webhooks_enabled
            && $this->supportsRecording()
            && $this->settings->decryptedZoomWebhookSecret() !== null;
    }

    public function verifyRecordingWebhookSignature(Request $request): bool
    {
        return $this->verifyZoomSignature($request, $this->settings->decryptedZoomWebhookSecret());
    }

    public function recordingWebhookChallengeResponse(Request $request): ?array
    {
        return $this->zoomChallengeResponse($request, $this->settings->decryptedZoomWebhookSecret());
    }

    /**
     * Reduces a verified Zoom event to a signal. Deliberately keeps
     * NOTHING from the payload beyond identifiers: the recording files,
     * their download URLs and the short-lived download token are all
     * discarded, and re-fetched server-side at ingestion time.
     */
    public function parseRecordingWebhook(Request $request): RecordingWebhookResult
    {
        $event = $request->input('event');
        $meetingId = $request->input('payload.object.id');
        $uuid = $request->input('payload.object.uuid');
        $eventTs = $request->input('event_ts');

        if (! is_string($event) || $event === '') {
            throw new InvalidRecordingWebhookException('Zoom webhook is missing an event name.');
        }

        if (blank($meetingId) && blank($uuid)) {
            throw new InvalidRecordingWebhookException('Zoom webhook is missing a meeting identifier.');
        }

        return new RecordingWebhookResult(
            provider: self::KEY,
            // Deterministic for a given delivery, so a Zoom redelivery
            // collides on the unique index instead of re-ingesting.
            eventId: sprintf('%s:%s:%s', $event, $uuid ?? $meetingId, $eventTs ?? 'na'),
            eventType: $event,
            meetingReference: filled($meetingId) ? (string) $meetingId : null,
            // Only a COMPLETED cloud recording is ingestable. Other Zoom
            // recording events (started, stopped, transcript ready) are
            // accepted and ignored — acknowledging them stops Zoom
            // retrying, without pretending there is anything to fetch.
            recordingReady: $event === self::EVENT_RECORDING_COMPLETED,
        );
    }

    // ── MeetingRecordingProviderInterface / DiscoversRecordingArtifacts ──

    /**
     * A configuration declaration, never a network call. False unless
     * Zoom recording is explicitly switched on AND the credentials are
     * present — so a deployment without a Zoom subscription declines
     * recording cleanly instead of failing lessons.
     */
    public function supportsRecording(): bool
    {
        return $this->recordings->isConfigured();
    }

    public function discoverRecording(BookingMeeting $meeting): ?DiscoveredRecording
    {
        return $this->recordings->discover($meeting);
    }

    public function stageRecording(DiscoveredRecording $discovered): StagedRecordingFile
    {
        return $this->stager->stage($discovered);
    }

    /**
     * The base-contract path, composed from the same two steps so there
     * is one implementation of each. A Zoom recording always streams —
     * it lives in Zoom's cloud, not in SIRI's storage backend — so this
     * costs the same as the discovery path and exists only for callers
     * that do not use discovery.
     */
    public function fetchRecording(BookingMeeting $meeting): ?ProviderRecordingResult
    {
        $discovered = $this->discoverRecording($meeting);

        if ($discovered === null) {
            return null;
        }

        return new ProviderRecordingResult(
            providerReference: $discovered->providerReference,
            file: $this->stageRecording($discovered),
            durationSeconds: $discovered->durationSeconds,
            recordedAt: $discovered->recordedAt,
        );
    }

    /** @param  array{id: string, join_url: ?string, start_url: ?string, password: ?string, timezone: ?string, status: ?string}  $meeting */
    private function resultFromMeeting(
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        string $timezone,
        array $meeting,
    ): MeetingCreationResult {
        // Unlike Google's async conference creation, a successful Zoom
        // create/update always carries join_url — its absence means the
        // response wasn't a meeting at all.
        if (blank($meeting['join_url'])) {
            throw new BookingException('Zoom did not return a join URL for the meeting.');
        }

        return new MeetingCreationResult(
            provider: self::KEY,
            providerMeetingId: $meeting['id'] !== '' ? $meeting['id'] : null,
            providerEventId: null,
            joinUrl: $meeting['join_url'],
            hostUrl: $meeting['start_url'],
            password: $meeting['password'],
            startsAt: $startsAt,
            endsAt: $endsAt,
            timezone: $timezone,
            status: MeetingStatus::Created,
            metadata: array_filter(['zoom_status' => $meeting['status']]),
        );
    }

    /** @return array<string, mixed> */
    private function meetingPayload(Booking $booking, CarbonImmutable $startsAt, CarbonImmutable $endsAt, string $timezone): array
    {
        return [
            'topic' => $this->safeTitle($booking),
            'type' => self::TYPE_SCHEDULED,
            'start_time' => $startsAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'duration' => max(1, (int) $startsAt->diffInMinutes($endsAt)),
            'timezone' => $timezone,
            'agenda' => $this->safeDescription($booking),
            'settings' => [
                'join_before_host' => false,
                'waiting_room' => true,
                'mute_upon_entry' => true,
                // The full SIRI eligibility chain decides, never a
                // hardcode: platform + country + per-provider recording
                // switch + both participants' consent. A lesson SIRI
                // will not record is created with recording OFF at
                // Zoom, so the provider never records something the
                // participants did not agree to.
                //
                // 'cloud' and never 'local': a local recording lands on
                // the host's own computer, outside SIRI's storage,
                // retention and access control entirely.
                'auto_recording' => $this->recordingEligibility->evaluate($booking, $this)->eligible ? 'cloud' : 'none',
                'host_video' => true,
                'participant_video' => false,
            ],
        ];
    }

    private function timezoneFor(Booking $booking): string
    {
        return $booking->timezone
            ?? $this->settings->zoom_default_timezone
            ?? (string) config('app.timezone', 'UTC');
    }

    private function hostUser(): string
    {
        return $this->settings->zoom_host_user_id
            ?? $this->settings->zoom_host_email
            ?? throw new BookingException('No Zoom host user is configured.');
    }
}
