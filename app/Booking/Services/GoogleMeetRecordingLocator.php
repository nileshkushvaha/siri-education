<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\GoogleMeetClient;
use App\Booking\DTOs\DiscoveredRecording;
use App\Booking\DTOs\NativeRecordingSource;
use App\Booking\Enums\RecordingFailureCode;
use App\Booking\Exceptions\GatewayRequestException;
use App\Booking\Exceptions\RecordingIngestionException;
use App\Booking\Storage\GoogleDriveRecordingStorage;
use App\Models\BookingMeeting;
use App\Settings\MeetingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Answers one question: which Google Meet recording artifact — if any
 * — belongs to THIS SIRI lesson?
 *
 * Getting that wrong would be the worst possible defect in this
 * feature: one student's class delivered to another. So the mapping
 * uses only immutable provider identifiers plus an explicit time
 * window, and refuses to guess:
 *
 *   BookingMeeting.provider_meeting_id   ← Meet meeting CODE, persisted
 *          │                               at creation by Calendar
 *          ▼
 *   conferenceRecords.list(
 *       space.meeting_code = <code>
 *       AND start_time within the lesson's own window)
 *          │
 *          ▼  a space can host MANY conferences over its lifetime;
 *             the window is what pins this one to this lesson
 *   conferenceRecords/{id}
 *          │
 *          ▼
 *   recordings.list → state + driveDestination.file
 *
 * Nothing is ever matched on a meeting title, a participant name, or a
 * formatted date. If no conference falls inside the window, the answer
 * is "nothing yet" — never "the closest one".
 */
final class GoogleMeetRecordingLocator
{
    /**
     * How far before the scheduled start and after the scheduled end a
     * conference may begin and still count as this lesson. Generous
     * enough for an early-joining instructor or an overrunning class,
     * far tighter than the gap between two lessons in the same space.
     */
    private const WINDOW_BEFORE_MINUTES = 60;

    private const WINDOW_AFTER_MINUTES = 240;

    public function __construct(
        private readonly GoogleMeetClient $meet,
        private readonly MeetingSettings $settings,
    ) {}

    /**
     * True when every piece of configuration this lookup needs is
     * present. Never performs I/O — so an unconfigured deployment
     * declines the recording capability instead of failing per lesson.
     */
    public function isConfigured(): bool
    {
        return $this->settings->google_meet_recording_enabled
            && filled($this->settings->platform_meeting_account)
            && $this->settings->decryptedGoogleCredentials() !== null;
    }

    /**
     * Null means "no artifact for this lesson yet" — the conference has
     * not been reconciled by Google, or the file is still generating.
     * Both are expected transient states, never failures.
     *
     * @throws RecordingIngestionException on a classified provider failure
     */
    public function discover(BookingMeeting $meeting): ?DiscoveredRecording
    {
        $meetingCode = $this->meetingCode($meeting);

        if ($meetingCode === null) {
            // No Meet identifier was ever persisted for this meeting —
            // no amount of retrying will produce one.
            throw new RecordingIngestionException(
                RecordingFailureCode::ProviderCapabilityMissing,
                'Meeting has no Google Meet meeting code to resolve a conference record from.',
            );
        }

        $credentials = $this->credentialsOrFail();
        $subject = $this->subjectOrFail();
        [$from, $to] = $this->window($meeting);

        try {
            $conferences = $this->meet->listConferenceRecords(
                $credentials,
                $subject,
                $meetingCode,
                $from->toIso8601ZuluString('millisecond'),
                $to->toIso8601ZuluString('millisecond'),
            );
        } catch (GatewayRequestException $e) {
            throw $this->translate($e);
        }

        if ($conferences === []) {
            return null; // Google has not reconciled a conference yet.
        }

        return $this->firstGeneratedRecording($credentials, $subject, $conferences, $meeting);
    }

    // ── Internals ──────────────────────────────────────────────────────

    /**
     * Walks the conference records for this lesson and returns the
     * earliest FILE_GENERATED recording.
     *
     * "Earliest" is a DETERMINISTIC rule, not a convenience: Google
     * legitimately returns one recording per record/stop session, and
     * an unordered pick would make which video a student sees depend
     * on API response ordering.
     *
     * @param  list<array{name: string, startTime: ?string, endTime: ?string, space: ?string}>  $conferences
     */
    private function firstGeneratedRecording(
        string $credentials,
        string $subject,
        array $conferences,
        BookingMeeting $meeting,
    ): ?DiscoveredRecording {
        $candidates = [];
        $pendingGeneration = false;

        foreach ($conferences as $conference) {
            try {
                $recordings = $this->meet->listRecordings($credentials, $subject, $conference['name']);
            } catch (GatewayRequestException $e) {
                throw $this->translate($e);
            }

            foreach ($recordings as $recording) {
                if ($recording['state'] !== 'FILE_GENERATED' || blank($recording['driveFileId'])) {
                    // STARTED / ENDED: the conference recorded, but the
                    // file does not exist yet. Transient by definition.
                    $pendingGeneration = true;

                    continue;
                }

                $candidates[] = $recording;
            }
        }

        if ($candidates === []) {
            if ($pendingGeneration) {
                Log::info('Google Meet recording is still being generated', [
                    'booking_meeting_id' => $meeting->getKey(),
                    'conference_records' => count($conferences),
                ]);
            }

            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => strcmp((string) $a['startTime'], (string) $b['startTime']));

        $selected = $candidates[0];
        $count = count($candidates);

        return new DiscoveredRecording(
            // The Meet recording RESOURCE NAME — immutable and unique
            // per artifact, so re-discovering the same recording is
            // recognisably the same recording.
            providerReference: $selected['name'],
            recordedAt: $this->timestamp($selected['startTime']) ?? $meeting->ends_at,
            durationSeconds: $this->duration($selected['startTime'], $selected['endTime']),
            // The artifact is already an object in Google Drive. Whether
            // that lets us skip a download entirely is the storage
            // layer's decision, not this class's.
            nativeSource: new NativeRecordingSource(
                driver: GoogleDriveRecordingStorage::KEY,
                reference: (string) $selected['driveFileId'],
            ),
            artifactCount: $count,
        );
    }

    /**
     * Calendar persists the Meet meeting code as provider_meeting_id.
     * The join URL is a documented fallback for meetings created before
     * that was captured, or if Google ever returns a null conferenceId
     * on an otherwise successful async conference creation.
     */
    private function meetingCode(BookingMeeting $meeting): ?string
    {
        $candidate = $meeting->provider_meeting_id;

        if ($this->looksLikeMeetingCode($candidate)) {
            return $candidate;
        }

        $path = trim((string) parse_url((string) $meeting->join_url, PHP_URL_PATH), '/');

        return $this->looksLikeMeetingCode($path) ? $path : null;
    }

    /** Meet codes are `xxx-xxxx-xxx`; anything else is some other provider's identifier. */
    private function looksLikeMeetingCode(?string $value): bool
    {
        return $value !== null && preg_match('/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/i', $value) === 1;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function window(BookingMeeting $meeting): array
    {
        $start = $meeting->starts_at ?? $meeting->ends_at ?? CarbonImmutable::now();
        $end = $meeting->ends_at ?? $start;

        return [
            $start->subMinutes(self::WINDOW_BEFORE_MINUTES)->utc(),
            $end->addMinutes(self::WINDOW_AFTER_MINUTES)->utc(),
        ];
    }

    private function timestamp(?string $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function duration(?string $start, ?string $end): ?int
    {
        $startsAt = $this->timestamp($start);
        $endsAt = $this->timestamp($end);

        if ($startsAt === null || $endsAt === null || $endsAt->lessThanOrEqualTo($startsAt)) {
            return null;
        }

        return (int) $startsAt->diffInSeconds($endsAt);
    }

    /**
     * Maps a Meet API failure onto the domain's own vocabulary, so the
     * retry decision never depends on a Google exception type or on
     * string-matching an error message downstream.
     */
    private function translate(GatewayRequestException $e): RecordingIngestionException
    {
        $message = strtolower($e->getMessage());

        $code = match (true) {
            str_contains($message, 'unauthorized_client'),
            str_contains($message, 'oauth token error'),
            str_contains($message, 'invalid_grant') => RecordingFailureCode::StorageAuthFailed,
            str_contains($message, 'permission_denied'),
            str_contains($message, 'forbidden'),
            str_contains($message, 'http 403') => RecordingFailureCode::SourceAccessDenied,
            str_contains($message, 'resource_exhausted'),
            str_contains($message, 'rate limit'),
            str_contains($message, 'quota'),
            str_contains($message, 'http 429') => RecordingFailureCode::SourceRateLimited,
            // A conference record expires on Google's side (30 days for
            // some artifacts); once gone it never comes back.
            str_contains($message, 'not_found'),
            str_contains($message, 'http 404') => RecordingFailureCode::SourceExpired,
            default => RecordingFailureCode::SourceDownloadFailed,
        };

        return new RecordingIngestionException($code, $e->getMessage(), previous: $e);
    }

    private function credentialsOrFail(): string
    {
        return $this->settings->decryptedGoogleCredentials()
            ?? throw new RecordingIngestionException(
                RecordingFailureCode::StorageNotConfigured,
                'Google credentials are not configured.',
            );
    }

    private function subjectOrFail(): string
    {
        return $this->settings->platform_meeting_account
            ?? throw new RecordingIngestionException(
                RecordingFailureCode::StorageNotConfigured,
                'Google Meet platform account (delegated Workspace user) is not configured.',
            );
    }
}
