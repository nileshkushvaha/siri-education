<?php

declare(strict_types=1);

namespace App\Booking\Meetings;

use App\Booking\Contracts\DiscoversRecordingArtifacts;
use App\Booking\Contracts\GoogleCalendarClient;
use App\Booking\Contracts\GoogleMeetClient;
use App\Booking\Contracts\MeetingProviderInterface;
use App\Booking\Contracts\MeetingRecordingProviderInterface;
use App\Booking\DTOs\DiscoveredRecording;
use App\Booking\DTOs\MeetingCancellationResult;
use App\Booking\DTOs\MeetingCreationContext;
use App\Booking\DTOs\MeetingCreationResult;
use App\Booking\DTOs\MeetingUpdateContext;
use App\Booking\DTOs\ProviderRecordingResult;
use App\Booking\DTOs\StagedRecordingFile;
use App\Booking\Enums\MeetingStatus;
use App\Booking\Exceptions\BookingException;
use App\Booking\Meetings\Concerns\BuildsSafeMeetingContent;
use App\Booking\Meetings\Concerns\SanitizesProviderMessages;
use App\Booking\Services\GoogleMeetRecordingLocator;
use App\Booking\Services\GoogleMeetRecordingStager;
use App\Booking\Services\RecordingAvailabilityResolver;
use App\Country\Services\CountryResolver;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Settings\MeetingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates a Google Calendar event with Meet conference data
 * (`conferenceData.createRequest`, `conferenceDataVersion = 1`) for a
 * confirmed booking. Conference creation is asynchronous on Google's
 * side: a join URL is not assumed to exist immediately — see
 * resultFromEvent().
 *
 * No attendees are added to the event (business decision: create on
 * the platform calendar only; the app's own notification pipeline
 * tells the student/instructor about the link, since a calendar-invite
 * notification policy is not built in this phase).
 *
 * RECORDING. This provider also supplies lesson recordings (SRS
 * §12.31). Meet writes a finished class recording as an MP4 into the
 * platform account's Google Drive and exposes its Drive file id
 * through the Meet REST API, so acquisition is a metadata lookup
 * rather than a download — see GoogleMeetRecordingLocator for the
 * meeting→conference→artifact mapping, and
 * DiscoversRecordingArtifacts for why discovery and transfer are
 * separate steps. Recording capability is declared only when the Meet
 * lookup is fully configured, so a deployment without the Meet scope
 * granted simply reports no recording support instead of failing every
 * lesson.
 *
 * Never logs or persists the raw Google API response, the credentials
 * JSON, or an access/refresh token — GoogleCalendarClient already
 * returns a minimal plain array, and exception messages are sanitized
 * before they reach BookingMeetingService.
 */
final class GoogleCalendarMeetProvider implements DiscoversRecordingArtifacts, MeetingProviderInterface, MeetingRecordingProviderInterface
{
    use BuildsSafeMeetingContent;
    use SanitizesProviderMessages;

    public const string KEY = 'google_meet';

    /**
     * The Google Calendar API's own conference-type constant —
     * deliberately distinct from self::KEY ('google_meet'), which is
     * this codebase's internal provider identifier, not something
     * Google's API ever accepts.
     */
    private const string GOOGLE_MEET_CONFERENCE_TYPE = 'hangoutsMeet';

    public function __construct(
        private readonly GoogleCalendarClient $client,
        private readonly GoogleMeetClient $meet,
        private readonly MeetingSettings $settings,
        private readonly GoogleMeetRecordingLocator $recordings,
        private readonly GoogleMeetRecordingStager $stager,
        private readonly RecordingAvailabilityResolver $availability,
        private readonly CountryResolver $countries,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function isConfigured(): bool
    {
        return $this->settings->google_meet_enabled
            && $this->settings->google_auth_type === 'service_account'
            && filled($this->settings->google_calendar_id)
            // Domain-wide delegation requires an impersonated Workspace
            // user — a bare service account has no Meet entitlement and
            // fails with Google's "Invalid conference type value" (see
            // GoogleCalendarSdkClient::service()).
            && filled($this->settings->platform_meeting_account)
            && $this->settings->decryptedGoogleCredentials() !== null;
    }

    public function createMeeting(Booking $booking, MeetingCreationContext $context): MeetingCreationResult
    {
        $requestId = 'meet-'.$booking->id.'-'.Str::random(8);
        $credentials = $this->credentialsOrFail();
        $calendarId = $this->calendarIdOrFail();
        $subject = $this->delegatedSubjectOrFail();

        $this->assertMeetSupported($credentials, $calendarId, $subject);

        // AUTO-RECORDING. A lesson the platform will record gets a space
        // created through the Meet API with automatic recording ON, so
        // nobody has to press Record. Meet only allows that configuration
        // on spaces the app created itself — a Calendar-created
        // conference can never carry it — which is why the space comes
        // first and the Calendar event is attached to it. If the space
        // cannot be created (settings scope not yet granted, Meet API
        // down) the lesson still gets a Calendar-created conference,
        // recorded manually as before: a missing auto-record must never
        // cost a lesson its meeting.
        $space = $this->autoRecordingSpace($booking, $credentials, $subject);
        $payload = $this->eventPayload($booking, $context, $requestId, $space);

        try {
            $event = $this->client->insertEvent($credentials, $calendarId, $payload, $subject);
        } catch (Throwable $e) {
            if ($space === null) {
                throw new BookingException($this->sanitize($e->getMessage()));
            }

            // Calendar refused the attached conference: keep the space
            // (it is what records) and carry the link as the event's
            // location instead, so the platform calendar still shows it.
            Log::warning('Google Calendar refused the attached Meet space; retrying with the link as location.', [
                'booking_id' => $booking->id,
                'reason' => $this->sanitize($e->getMessage()),
            ]);

            $fallback = $this->eventPayload($booking, $context, $requestId, null);
            unset($fallback['conferenceRequestId']);
            $fallback['location'] = $space['meetingUri'];

            try {
                $event = $this->client->insertEvent($credentials, $calendarId, $fallback, $subject);
            } catch (Throwable $again) {
                throw new BookingException($this->sanitize($again->getMessage()));
            }
        }

        if ($space !== null) {
            return $this->resultFromSpace($booking, $space, $event);
        }

        return $this->resultFromEvent($booking->starts_at, $booking->ends_at, $booking->timezone, $event);
    }

    /**
     * Whether THIS lesson will be recorded by the platform — the same
     * platform capability and per-provider switch RecordingEligibilityResolver
     * applies, evaluated before the meeting exists. Consent is
     * platform-wide (docs/decisions.md) and is not re-checked here.
     */
    private function shouldAutoRecord(Booking $booking): bool
    {
        if (! $this->recordings->isConfigured()) {
            return false;
        }

        $student = $booking->student;

        return $student !== null && $this->availability->isAvailable($this->countries->forStudent($student));
    }

    /** @return array{name: string, meetingCode: string, meetingUri: string}|null */
    private function autoRecordingSpace(Booking $booking, string $credentials, string $subject): ?array
    {
        if (! $this->shouldAutoRecord($booking)) {
            return null;
        }

        try {
            return $this->meet->createSpace($credentials, $subject, autoRecording: true);
        } catch (Throwable $e) {
            Log::warning('Google Meet auto-recording space could not be created; falling back to a Calendar-created conference (manual Record).', [
                'booking_id' => $booking->id,
                'reason' => $this->sanitize($e->getMessage()),
            ]);

            return null;
        }
    }

    /**
     * A meeting on a Meet-API-created space is Created the moment the
     * space exists: unlike a Calendar conference there is no asynchronous
     * creation to wait for. The meeting code is the space's own, which is
     * exactly what GoogleMeetRecordingLocator later matches on.
     *
     * @param  array{name: string, meetingCode: string, meetingUri: string}  $space
     * @param  array{id: string, hangoutLink: ?string, conferenceData: array<string, mixed>}  $event
     */
    private function resultFromSpace(Booking $booking, array $space, array $event): MeetingCreationResult
    {
        return new MeetingCreationResult(
            provider: self::KEY,
            providerMeetingId: $space['meetingCode'],
            providerEventId: $event['id'] ?? null,
            joinUrl: $space['meetingUri'],
            hostUrl: null,
            password: null,
            startsAt: $booking->starts_at,
            endsAt: $booking->ends_at,
            timezone: $booking->timezone,
            status: MeetingStatus::Created,
            metadata: ['conference_status' => 'success', 'auto_recording' => true, 'space' => $space['name']],
        );
    }

    public function updateMeeting(BookingMeeting $meeting, MeetingUpdateContext $context): MeetingCreationResult
    {
        $startsAt = $context->startsAt ?? $meeting->starts_at;
        $endsAt = $context->endsAt ?? $meeting->ends_at;
        $timezone = $context->timezone ?? $meeting->timezone;
        $requestId = 'meet-retry-'.$meeting->booking_id.'-'.Str::random(8);

        $payload = [
            'summary' => $this->safeTitle($meeting->booking),
            'description' => $this->safeDescription($meeting->booking),
            'start' => ['dateTime' => $startsAt->toIso8601String(), 'timeZone' => $timezone],
            'end' => ['dateTime' => $endsAt->toIso8601String(), 'timeZone' => $timezone],
            'conferenceRequestId' => $requestId,
        ];

        $credentials = $this->credentialsOrFail();
        $calendarId = $this->calendarIdOrFail();
        $subject = $this->delegatedSubjectOrFail();

        $this->assertMeetSupported($credentials, $calendarId, $subject);

        try {
            $event = $meeting->provider_event_id !== null
                ? $this->client->updateEvent($credentials, $calendarId, $meeting->provider_event_id, $payload, $subject)
                : $this->client->insertEvent($credentials, $calendarId, $payload, $subject);
        } catch (Throwable $e) {
            throw new BookingException($this->sanitize($e->getMessage()));
        }

        return $this->resultFromEvent($startsAt, $endsAt, $timezone, $event);
    }

    public function cancelMeeting(BookingMeeting $meeting): MeetingCancellationResult
    {
        if ($meeting->provider_event_id === null) {
            return new MeetingCancellationResult(status: MeetingStatus::Cancelled);
        }

        try {
            $this->client->deleteEvent($this->credentialsOrFail(), $this->calendarIdOrFail(), $meeting->provider_event_id, $this->delegatedSubjectOrFail());
        } catch (Throwable $e) {
            return new MeetingCancellationResult(status: MeetingStatus::Failed, failureReason: $this->sanitize($e->getMessage()));
        }

        return new MeetingCancellationResult(status: MeetingStatus::Cancelled);
    }

    // ── MeetingRecordingProviderInterface / DiscoversRecordingArtifacts ──

    /**
     * A configuration declaration, never a network call. False when
     * the Meet recording lookup is switched off or incompletely
     * configured — which makes the whole recording pipeline decline
     * cleanly (RecordingEligibilityResolver) rather than registering
     * recordings that could never be fetched.
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
     * The base-contract path, kept for callers that do not use
     * discovery. Composed from the same two steps so there is exactly
     * one implementation of each — this always stages, and therefore
     * always costs a full download; RecordingIngestionService prefers
     * discoverRecording() precisely to avoid that.
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

    /**
     * Fails fast with a clear domain exception before ever calling
     * insert/update — a calendar/account that cannot create a
     * hangoutsMeet conference will otherwise 400 with Google's opaque
     * "Invalid conference type value.", which is exactly the bug this
     * check exists to turn into an actionable error.
     */
    private function assertMeetSupported(string $credentials, string $calendarId, string $subject): void
    {
        try {
            $allowed = $this->client->allowedConferenceTypes($credentials, $calendarId, $subject);
        } catch (Throwable $e) {
            throw new BookingException($this->sanitize($e->getMessage()));
        }

        if (! in_array(self::GOOGLE_MEET_CONFERENCE_TYPE, $allowed, true)) {
            throw new BookingException(sprintf(
                'Google Meet is not supported by the configured calendar for %s. Allowed conference types: [%s]',
                $subject,
                implode(', ', $allowed) ?: 'none',
            ));
        }
    }

    /** @param  array{id: string, hangoutLink: ?string, conferenceData: array<string, mixed>}  $event */
    private function resultFromEvent(
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        string $timezone,
        array $event,
    ): MeetingCreationResult {
        $conferenceData = $event['conferenceData'] ?? [];
        $joinUrl = $this->extractMeetUrl($conferenceData);
        $conferenceStatus = $conferenceData['status'] ?? ($joinUrl !== null ? 'success' : 'pending');

        // Async conference creation: never assume a join link exists
        // just because the event insert/update call itself succeeded.
        $status = match (true) {
            $joinUrl !== null && $conferenceStatus === 'success' => MeetingStatus::Created,
            $conferenceStatus === 'failure' => MeetingStatus::Failed,
            default => MeetingStatus::Pending,
        };

        return new MeetingCreationResult(
            provider: self::KEY,
            providerMeetingId: $conferenceData['conferenceId'] ?? null,
            providerEventId: $event['id'] ?? null,
            joinUrl: $joinUrl,
            hostUrl: null, // Calendar API exposes no distinct host/start link for Meet.
            password: null,
            startsAt: $startsAt,
            endsAt: $endsAt,
            timezone: $timezone,
            status: $status,
            metadata: ['conference_status' => $conferenceStatus],
        );
    }

    /** @param  array<string, mixed>  $conferenceData */
    private function extractMeetUrl(array $conferenceData): ?string
    {
        foreach ($conferenceData['entryPoints'] ?? [] as $entryPoint) {
            if (($entryPoint['entryPointType'] ?? null) === 'video') {
                return $entryPoint['uri'] ?? null;
            }
        }

        return null;
    }

    /**
     * @param  array{name: string, meetingCode: string, meetingUri: string}|null  $space  an existing Meet space to attach instead of requesting a new conference
     * @return array<string, mixed>
     */
    private function eventPayload(Booking $booking, MeetingCreationContext $context, string $requestId, ?array $space = null): array
    {
        $startsAt = $context->startsAt ?? $booking->starts_at;
        $endsAt = $context->endsAt ?? $booking->ends_at;
        $timezone = $context->timezone ?? $booking->timezone;

        $payload = [
            'summary' => $this->safeTitle($booking),
            'description' => $this->safeDescription($booking),
            'start' => ['dateTime' => $startsAt->toIso8601String(), 'timeZone' => $timezone],
            'end' => ['dateTime' => $endsAt->toIso8601String(), 'timeZone' => $timezone],
        ];

        if ($space !== null) {
            $payload['attachConference'] = ['meetingCode' => $space['meetingCode'], 'meetingUri' => $space['meetingUri']];
        } else {
            $payload['conferenceRequestId'] = $requestId;
        }

        return $payload;
    }

    private function calendarIdOrFail(): string
    {
        return $this->settings->google_calendar_id ?? throw new BookingException('Google Calendar id is not configured.');
    }

    private function credentialsOrFail(): string
    {
        return $this->settings->decryptedGoogleCredentials()
            ?? throw new BookingException('Google credentials are not configured.');
    }

    private function delegatedSubjectOrFail(): string
    {
        return $this->settings->platform_meeting_account
            ?? throw new BookingException('Google Meet platform account (delegated Workspace user) is not configured.');
    }
}
