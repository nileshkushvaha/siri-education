<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

/**
 * Isolates the google/apiclient SDK behind a plain-array seam —
 * GoogleCalendarMeetProvider never touches \Google\Client or
 * \Google\Service\Calendar directly. Tests bind a fake implementation
 * instead of stubbing HTTP, matching RazorpayGatewayClient/
 * StripeGatewayClient's isolation pattern. Every method returns/accepts
 * plain arrays — never the SDK's Event object — so no raw SDK payload
 * ever reaches persistence.
 */
interface GoogleCalendarClient
{
    /**
     * $credentialsJson is the decrypted service-account JSON — this
     * client is stateless/credential-agnostic (mirrors
     * RazorpayGatewayClient::createOrder($keyId, $keySecret, …)); the
     * provider decrypts MeetingSettings::google_credentials_json and
     * passes it per call, never storing it on this class.
     *
     * @param  array<string, mixed>  $eventPayload
     * @return array{id: string, hangoutLink: ?string, conferenceData: array<string, mixed>}
     */
    public function insertEvent(string $credentialsJson, string $calendarId, array $eventPayload, int $conferenceDataVersion = 1): array;

    /**
     * @param  array<string, mixed>  $eventPayload
     * @return array{id: string, hangoutLink: ?string, conferenceData: array<string, mixed>}
     */
    public function updateEvent(string $credentialsJson, string $calendarId, string $eventId, array $eventPayload, int $conferenceDataVersion = 1): array;

    public function deleteEvent(string $credentialsJson, string $calendarId, string $eventId): void;
}
