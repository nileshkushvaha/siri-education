<?php

declare(strict_types=1);

namespace App\Booking\Gateways;

use App\Booking\Contracts\GoogleCalendarClient;
use App\Booking\Exceptions\GatewayRequestException;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\ConferenceData;
use Google\Service\Calendar\ConferenceSolutionKey;
use Google\Service\Calendar\CreateConferenceRequest;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Illuminate\Support\Str;
use Throwable;

/**
 * Wraps the official `google/apiclient` SDK — the only class in this
 * codebase that ever instantiates \Google\Client or
 * \Google\Service\Calendar. Everything above GoogleCalendarMeetProvider
 * stays SDK-free and only ever sees plain arrays.
 *
 * Stateless/credential-agnostic like RazorpaySdkClient: the decrypted
 * service-account JSON is passed in per call, never stored on this
 * class or logged.
 */
final class GoogleCalendarSdkClient implements GoogleCalendarClient
{
    public function insertEvent(string $credentialsJson, string $calendarId, array $eventPayload, int $conferenceDataVersion = 1): array
    {
        try {
            $service = $this->service($credentialsJson);
            $event = $service->events->insert(
                $calendarId,
                $this->buildEvent($eventPayload),
                ['conferenceDataVersion' => $conferenceDataVersion],
            );

            return $this->toArray($event);
        } catch (Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }

    public function updateEvent(string $credentialsJson, string $calendarId, string $eventId, array $eventPayload, int $conferenceDataVersion = 1): array
    {
        try {
            $service = $this->service($credentialsJson);
            $event = $service->events->update(
                $calendarId,
                $eventId,
                $this->buildEvent($eventPayload),
                ['conferenceDataVersion' => $conferenceDataVersion],
            );

            return $this->toArray($event);
        } catch (Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }

    public function deleteEvent(string $credentialsJson, string $calendarId, string $eventId): void
    {
        try {
            $this->service($credentialsJson)->events->delete($calendarId, $eventId);
        } catch (Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }

    private function service(string $credentialsJson): Calendar
    {
        $client = new Client;
        $client->setApplicationName(config('app.name', 'Enterprise App').' Meetings');
        $client->setScopes([Calendar::CALENDAR_EVENTS]);
        $client->setAuthConfig(json_decode($credentialsJson, true, flags: JSON_THROW_ON_ERROR));

        return new Calendar($client);
    }

    /** @param  array<string, mixed>  $payload */
    private function buildEvent(array $payload): Event
    {
        $event = new Event;
        $event->setSummary($payload['summary']);
        $event->setDescription($payload['description']);

        $start = new EventDateTime;
        $start->setDateTime($payload['start']['dateTime']);
        $start->setTimeZone($payload['start']['timeZone']);
        $event->setStart($start);

        $end = new EventDateTime;
        $end->setDateTime($payload['end']['dateTime']);
        $end->setTimeZone($payload['end']['timeZone']);
        $event->setEnd($end);

        $solutionKey = new ConferenceSolutionKey;
        $solutionKey->setType('hangoutsMeet');

        $createRequest = new CreateConferenceRequest;
        $createRequest->setRequestId($payload['conferenceRequestId'] ?? Str::uuid()->toString());
        $createRequest->setConferenceSolutionKey($solutionKey);

        $conferenceData = new ConferenceData;
        $conferenceData->setCreateRequest($createRequest);
        $event->setConferenceData($conferenceData);

        return $event;
    }

    /**
     * Converts the SDK's Event object to a plain, minimal array
     * immediately — no raw SDK object or full API response ever
     * propagates past this method.
     *
     * @return array{id: string, hangoutLink: ?string, conferenceData: array<string, mixed>}
     */
    private function toArray(Event $event): array
    {
        $conferenceData = $event->getConferenceData();

        return [
            'id' => (string) $event->getId(),
            'hangoutLink' => $event->getHangoutLink(),
            'conferenceData' => $conferenceData !== null ? [
                'conferenceId' => $conferenceData->getConferenceId(),
                'status' => $conferenceData->getCreateRequest()?->getStatus()?->getStatusCode(),
                'entryPoints' => array_map(
                    static fn ($entryPoint): array => [
                        'entryPointType' => $entryPoint->getEntryPointType(),
                        'uri' => $entryPoint->getUri(),
                    ],
                    $conferenceData->getEntryPoints() ?? [],
                ),
            ] : [],
        ];
    }
}
