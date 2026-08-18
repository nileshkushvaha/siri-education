<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Booking\Contracts\ZoomMeetingClient;
use App\Booking\Exceptions\GatewayRequestException;

/**
 * A Zoom REST API that answers from an in-memory fixture and records
 * what was asked of it — no HTTP, no credentials, no tokens.
 *
 * `recordingFiles` deliberately holds the raw-ish file shape Zoom
 * returns (several MP4 layouts, an M4A, a chat log), because the
 * behaviour most worth testing is that SIRI picks the class VIDEO out
 * of that mixture rather than whatever came first.
 */
final class FakeZoomMeetingClient implements ZoomMeetingClient
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<array{hostUser: string, payload: array<string, mixed>}> */
    public array $created = [];

    /** @var list<array{meetingId: string, payload: array<string, mixed>}> */
    public array $updated = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var array<string, list<array<string, mixed>>> keyed by meeting id */
    public array $recordingFiles = [];

    /** @var list<array{url: string, token: ?string}> */
    public array $downloads = [];

    public string $downloadBytes = 'zoom recording bytes';

    public bool $credentialsValid = true;

    public ?GatewayRequestException $throwOnCreate = null;

    public ?GatewayRequestException $throwOnRecordings = null;

    public ?GatewayRequestException $throwOnDownload = null;

    /** Meetings with no recording at all — Zoom answers 404, which is not an error. */
    public array $meetingsWithoutRecordings = [];

    public function createMeeting(string $hostUser, array $payload): array
    {
        $this->calls[] = 'createMeeting';

        if ($this->throwOnCreate !== null) {
            throw $this->throwOnCreate;
        }

        $id = (string) (900000000 + count($this->created));
        $this->created[] = ['hostUser' => $hostUser, 'payload' => $payload];

        return [
            'id' => $id,
            'join_url' => 'https://zoom.us/j/'.$id,
            'start_url' => 'https://zoom.us/s/'.$id.'?zak=SENSITIVE-HOST-TOKEN',
            'password' => 'p'.$id,
            'timezone' => $payload['timezone'] ?? 'UTC',
            'status' => 'waiting',
        ];
    }

    public function updateMeeting(string $meetingId, array $payload): array
    {
        $this->calls[] = 'updateMeeting';
        $this->updated[] = ['meetingId' => $meetingId, 'payload' => $payload];

        return [
            'id' => $meetingId,
            'join_url' => 'https://zoom.us/j/'.$meetingId,
            'start_url' => 'https://zoom.us/s/'.$meetingId.'?zak=SENSITIVE-HOST-TOKEN',
            'password' => 'p'.$meetingId,
            'timezone' => $payload['timezone'] ?? 'UTC',
            'status' => 'waiting',
        ];
    }

    public function deleteMeeting(string $meetingId): bool
    {
        $this->calls[] = 'deleteMeeting';
        $this->deleted[] = $meetingId;

        return true;
    }

    public function listMeetingRecordings(string $meetingId): ?array
    {
        $this->calls[] = 'listMeetingRecordings';

        if ($this->throwOnRecordings !== null) {
            throw $this->throwOnRecordings;
        }

        if (in_array($meetingId, $this->meetingsWithoutRecordings, true)) {
            return null;
        }

        return ['uuid' => 'uuid-'.$meetingId, 'files' => $this->recordingFiles[$meetingId] ?? []];
    }

    public function openRecordingStream(string $downloadUrl, ?string $downloadToken = null)
    {
        $this->calls[] = 'openRecordingStream';

        if ($this->throwOnDownload !== null) {
            throw $this->throwOnDownload;
        }

        $this->downloads[] = ['url' => $downloadUrl, 'token' => $downloadToken];

        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $this->downloadBytes);
        rewind($stream);

        return $stream;
    }

    public function validateCredentials(): bool
    {
        $this->calls[] = 'validateCredentials';

        return $this->credentialsValid;
    }

    // ── Fixture builders ──────────────────────────────────────────────

    public function withRecordingFile(
        string $meetingId,
        string $id,
        string $fileType,
        ?string $recordingType = null,
        string $status = 'completed',
        ?int $size = 1024,
        ?string $start = null,
        ?string $end = null,
        ?string $downloadUrl = null,
    ): self {
        $this->recordingFiles[$meetingId][] = [
            'id' => $id,
            'file_type' => $fileType,
            'file_extension' => strtoupper($fileType),
            'recording_type' => $recordingType,
            'status' => $status,
            'file_size' => $size,
            'recording_start' => $start,
            'recording_end' => $end,
            'download_url' => $downloadUrl ?? 'https://zoom.us/rec/download/'.$id,
        ];

        return $this;
    }
}
