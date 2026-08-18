<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\ZoomMeetingClient;
use App\Booking\DTOs\DiscoveredRecording;
use App\Booking\Enums\RecordingFailureCode;
use App\Booking\Exceptions\GatewayRequestException;
use App\Booking\Exceptions\RecordingIngestionException;
use App\Models\BookingMeeting;
use App\Settings\MeetingSettings;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Answers, for one SIRI lesson: which Zoom cloud-recording file is the
 * class video?
 *
 * Two questions have to be got right, and both are places where a
 * careless implementation silently does the wrong thing.
 *
 * WHICH MEETING. `booking_meetings.provider_meeting_id` holds the Zoom
 * meeting id that Zoom itself returned when SIRI created the meeting.
 * Recordings are fetched by that id and nothing else — never by topic,
 * date, or host. A Zoom meeting id is per-meeting, so unlike Google
 * Meet's reusable space there is no ambiguity to resolve with a time
 * window here.
 *
 * WHICH FILE. Zoom returns an array of recording files for one meeting,
 * and it routinely contains more than the class video: an M4A audio
 * track, a chat transcript, a timeline, a closed-caption file, and
 * potentially SEVERAL MP4s for different layouts. Taking element zero
 * would eventually store an audio-only file or a chat log as though it
 * were the lesson. Selection is therefore explicit:
 *
 *   1. video files only (MP4), completed, with a download URL;
 *   2. ordered by config('recordings.zoom.preferred_layouts');
 *   3. ties broken by earliest recording_start, then largest file.
 *
 * Nothing about Google Drive or storage appears here — a Zoom recording
 * has no native source in SIRI's backend, so it always takes the
 * streaming path through RecordingStagingArea.
 */
final class ZoomRecordingLocator
{
    /** Zoom's own value for a finished file; anything else is still processing. */
    private const COMPLETED_STATUS = 'completed';

    private const VIDEO_FILE_TYPE = 'MP4';

    public function __construct(
        private readonly ZoomMeetingClient $client,
        private readonly MeetingSettings $settings,
    ) {}

    /**
     * Configuration declaration only — never performs I/O, so an
     * unconfigured deployment declines the recording capability instead
     * of failing every lesson.
     */
    public function isConfigured(): bool
    {
        return $this->settings->zoom_recording_enabled
            && $this->settings->zoom_enabled
            && filled($this->settings->zoom_account_id)
            && filled($this->settings->zoom_client_id)
            && $this->settings->decryptedZoomClientSecret() !== null;
    }

    /**
     * Null means "nothing to ingest yet" — Zoom has no recording for
     * this meeting, or the file is still processing. Both are expected
     * transient states: Zoom cloud recordings are produced
     * asynchronously and routinely lag the end of the class.
     *
     * @throws RecordingIngestionException on a classified provider failure
     */
    public function discover(BookingMeeting $meeting): ?DiscoveredRecording
    {
        $meetingId = $meeting->provider_meeting_id;

        if (blank($meetingId)) {
            throw new RecordingIngestionException(
                RecordingFailureCode::ProviderCapabilityMissing,
                'Meeting has no Zoom meeting id to resolve a recording from.',
            );
        }

        try {
            $recordings = $this->client->listMeetingRecordings($meetingId);
        } catch (GatewayRequestException $e) {
            throw $this->translate($e);
        }

        if ($recordings === null || $recordings['files'] === []) {
            return null;
        }

        return $this->selectVideo($recordings['files']);
    }

    // ── Internals ──────────────────────────────────────────────────────

    /**
     * @param  list<array<string, mixed>>  $files
     */
    private function selectVideo(array $files): ?DiscoveredRecording
    {
        $videos = array_values(array_filter($files, fn (array $file): bool => $this->isUsableVideo($file)));

        if ($videos === []) {
            // Zoom has files but no finished video — either still
            // processing, or the meeting produced audio/chat only.
            return null;
        }

        usort($videos, fn (array $a, array $b): int => $this->rank($a) <=> $this->rank($b)
            ?: strcmp((string) ($a['recording_start'] ?? ''), (string) ($b['recording_start'] ?? ''))
            ?: ((int) ($b['file_size'] ?? 0)) <=> ((int) ($a['file_size'] ?? 0)));

        $selected = $videos[0];

        return new DiscoveredRecording(
            // Zoom's own immutable file id — the same artifact is
            // recognisable as the same artifact across a webhook, a
            // replay and a reconciliation sweep.
            providerReference: (string) $selected['id'],
            recordedAt: $this->timestamp($selected['recording_start']) ?? CarbonImmutable::now(),
            durationSeconds: $this->duration($selected['recording_start'] ?? null, $selected['recording_end'] ?? null),
            // No native source: a Zoom recording lives in Zoom's cloud,
            // not in SIRI's storage backend, so it always streams.
            nativeSource: null,
            sizeBytes: isset($selected['file_size']) ? (int) $selected['file_size'] : null,
            mimeType: 'video/mp4',
            artifactCount: count($videos),
            // Transient only — consumed by ZoomRecordingStager within
            // this same attempt, never persisted or logged.
            providerHandle: (string) $selected['download_url'],
        );
    }

    /** @param  array<string, mixed>  $file */
    private function isUsableVideo(array $file): bool
    {
        return strtoupper((string) ($file['file_type'] ?? '')) === self::VIDEO_FILE_TYPE
            && strtolower((string) ($file['status'] ?? self::COMPLETED_STATUS)) === self::COMPLETED_STATUS
            && filled($file['download_url'] ?? null)
            && filled($file['id'] ?? null);
    }

    /**
     * Position in the configured layout preference. An unrecognised
     * layout sorts last rather than being discarded — a new Zoom layout
     * should degrade to "least preferred", never to "no recording".
     *
     * @param  array<string, mixed>  $file
     */
    private function rank(array $file): int
    {
        /** @var list<string> $preferred */
        $preferred = (array) config('recordings.zoom.preferred_layouts', []);
        $type = strtolower((string) ($file['recording_type'] ?? ''));
        $index = array_search($type, array_map('strtolower', $preferred), true);

        return $index === false ? count($preferred) : (int) $index;
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (blank($value) || ! is_string($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function duration(mixed $start, mixed $end): ?int
    {
        $startsAt = $this->timestamp($start);
        $endsAt = $this->timestamp($end);

        if ($startsAt === null || $endsAt === null || $endsAt->lessThanOrEqualTo($startsAt)) {
            return null;
        }

        return (int) $startsAt->diffInSeconds($endsAt);
    }

    /**
     * Maps a Zoom API failure onto the domain's own vocabulary, so the
     * retry decision never depends on a Zoom status code leaking
     * upward. Auth and rate-limit stay transient: both are routinely
     * fixed by an operator or by waiting.
     */
    private function translate(GatewayRequestException $e): RecordingIngestionException
    {
        $message = strtolower($e->getMessage());

        $code = match (true) {
            str_contains($message, 'invalid_client'),
            str_contains($message, 'credentials are missing'),
            str_contains($message, 'http 401') => RecordingFailureCode::StorageAuthFailed,
            str_contains($message, 'http 403'),
            str_contains($message, 'forbidden') => RecordingFailureCode::SourceAccessDenied,
            str_contains($message, 'http 429'),
            str_contains($message, 'rate limit') => RecordingFailureCode::SourceRateLimited,
            // Zoom expires cloud recordings on the account's own
            // schedule; once gone they never return.
            str_contains($message, 'http 410'),
            str_contains($message, 'expired') => RecordingFailureCode::SourceExpired,
            default => RecordingFailureCode::SourceDownloadFailed,
        };

        return new RecordingIngestionException($code, $e->getMessage(), previous: $e);
    }
}
