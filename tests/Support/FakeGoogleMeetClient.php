<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Booking\Contracts\GoogleMeetClient;
use App\Booking\Exceptions\GatewayRequestException;

/**
 * A Google Meet REST API that answers from an in-memory fixture and
 * records exactly what was asked of it.
 *
 * The filter string is captured verbatim rather than parsed, because
 * the property under test is that SIRI queries Google by the space's
 * immutable meeting code and an explicit time window — never by a
 * lesson title, a participant, or a formatted date. Conference records
 * are filtered here on the same terms so a lesson genuinely cannot
 * receive another lesson's conference.
 */
final class FakeGoogleMeetClient implements GoogleMeetClient
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<array{meetingCode: string, from: string, to: string}> */
    public array $conferenceQueries = [];

    /** @var array<string, list<array{name: string, startTime: ?string, endTime: ?string, space: ?string}>> keyed by meeting code */
    public array $conferenceRecords = [];

    /** @var array<string, list<array{name: string, state: ?string, startTime: ?string, endTime: ?string, driveFileId: ?string}>> keyed by conference record name */
    public array $recordings = [];

    public ?GatewayRequestException $throwOnConferenceList = null;

    public ?GatewayRequestException $throwOnRecordingList = null;

    public function requestedScopes(): array
    {
        return ['https://www.googleapis.com/auth/meetings.space.readonly'];
    }

    public function verifyTokenAcquisition(string $credentialsJson, string $delegatedSubject): void
    {
        $this->calls[] = 'verifyTokenAcquisition';
    }

    public function listConferenceRecords(
        string $credentialsJson,
        string $delegatedSubject,
        string $meetingCode,
        string $startTimeFrom,
        string $startTimeTo,
    ): array {
        $this->calls[] = 'listConferenceRecords';
        $this->conferenceQueries[] = ['meetingCode' => $meetingCode, 'from' => $startTimeFrom, 'to' => $startTimeTo];

        if ($this->throwOnConferenceList !== null) {
            throw $this->throwOnConferenceList;
        }

        // Google applies the filter server-side; this fake applies the
        // same one, so a conference outside the lesson's window is
        // invisible here exactly as it would be in production.
        return array_values(array_filter(
            $this->conferenceRecords[$meetingCode] ?? [],
            static fn (array $record): bool => $record['startTime'] === null
                || ($record['startTime'] >= $startTimeFrom && $record['startTime'] <= $startTimeTo),
        ));
    }

    public function listRecordings(
        string $credentialsJson,
        string $delegatedSubject,
        string $conferenceRecordName,
    ): array {
        $this->calls[] = 'listRecordings';

        if ($this->throwOnRecordingList !== null) {
            throw $this->throwOnRecordingList;
        }

        return $this->recordings[$conferenceRecordName] ?? [];
    }

    // ── Fixture builders ──────────────────────────────────────────────

    public function withConference(string $meetingCode, string $name, string $startTime, ?string $endTime = null): self
    {
        $this->conferenceRecords[$meetingCode][] = [
            'name' => $name,
            'startTime' => $startTime,
            'endTime' => $endTime,
            'space' => 'spaces/space-for-'.$meetingCode,
        ];

        return $this;
    }

    public function withRecording(
        string $conferenceRecordName,
        string $name,
        string $state,
        ?string $driveFileId,
        ?string $startTime = null,
        ?string $endTime = null,
    ): self {
        $this->recordings[$conferenceRecordName][] = [
            'name' => $name,
            'state' => $state,
            'startTime' => $startTime,
            'endTime' => $endTime,
            'driveFileId' => $driveFileId,
        ];

        return $this;
    }
}
