<?php

declare(strict_types=1);

namespace App\Booking\Meetings;

use App\Booking\Contracts\GoogleCalendarClient;
use App\Booking\Contracts\MeetingProviderInterface;
use App\Booking\DTOs\MeetingCancellationResult;
use App\Booking\DTOs\MeetingCreationContext;
use App\Booking\DTOs\MeetingCreationResult;
use App\Booking\DTOs\MeetingUpdateContext;
use App\Booking\Enums\MeetingStatus;
use App\Booking\Exceptions\BookingException;
use App\Booking\Meetings\Concerns\BuildsSafeMeetingContent;
use App\Booking\Meetings\Concerns\SanitizesProviderMessages;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Settings\MeetingSettings;
use Carbon\CarbonImmutable;
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
 * Never logs or persists the raw Google API response, the credentials
 * JSON, or an access/refresh token — GoogleCalendarClient already
 * returns a minimal plain array, and exception messages are sanitized
 * before they reach BookingMeetingService.
 */
final class GoogleCalendarMeetProvider implements MeetingProviderInterface
{
    use BuildsSafeMeetingContent;
    use SanitizesProviderMessages;

    public const string KEY = 'google_meet';

    public function __construct(
        private readonly GoogleCalendarClient $client,
        private readonly MeetingSettings $settings,
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
            && $this->settings->decryptedGoogleCredentials() !== null;
    }

    public function createMeeting(Booking $booking, MeetingCreationContext $context): MeetingCreationResult
    {
        $requestId = 'meet-'.$booking->id.'-'.Str::random(8);
        $payload = $this->eventPayload($booking, $context, $requestId);

        try {
            $event = $this->client->insertEvent(
                $this->credentialsOrFail(),
                $this->calendarIdOrFail(),
                $payload,
            );
        } catch (Throwable $e) {
            throw new BookingException($this->sanitize($e->getMessage()));
        }

        return $this->resultFromEvent($booking->starts_at, $booking->ends_at, $booking->timezone, $event);
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

        try {
            $event = $meeting->provider_event_id !== null
                ? $this->client->updateEvent($this->credentialsOrFail(), $this->calendarIdOrFail(), $meeting->provider_event_id, $payload)
                : $this->client->insertEvent($this->credentialsOrFail(), $this->calendarIdOrFail(), $payload);
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
            $this->client->deleteEvent($this->credentialsOrFail(), $this->calendarIdOrFail(), $meeting->provider_event_id);
        } catch (Throwable $e) {
            return new MeetingCancellationResult(status: MeetingStatus::Failed, failureReason: $this->sanitize($e->getMessage()));
        }

        return new MeetingCancellationResult(status: MeetingStatus::Cancelled);
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

    /** @return array<string, mixed> */
    private function eventPayload(Booking $booking, MeetingCreationContext $context, string $requestId): array
    {
        $startsAt = $context->startsAt ?? $booking->starts_at;
        $endsAt = $context->endsAt ?? $booking->ends_at;
        $timezone = $context->timezone ?? $booking->timezone;

        return [
            'summary' => $this->safeTitle($booking),
            'description' => $this->safeDescription($booking),
            'start' => ['dateTime' => $startsAt->toIso8601String(), 'timeZone' => $timezone],
            'end' => ['dateTime' => $endsAt->toIso8601String(), 'timeZone' => $timezone],
            'conferenceRequestId' => $requestId,
        ];
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
}
