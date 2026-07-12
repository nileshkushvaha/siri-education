<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

/**
 * Non-secret runtime diagnostics for the Google Calendar/Meet
 * integration — surfaced by GoogleCalendarConfigurationService's live
 * "Test Google Configuration" check. Every field here is safe to show
 * an admin or write to a notification: no private key, private_key_id,
 * access token, refresh token, or raw credential JSON ever belongs on
 * this DTO.
 */
final readonly class GoogleCalendarDiagnostics
{
    public function __construct(
        public ?string $clientId,
        public ?string $clientEmail,
        public ?string $delegatedSubject,
        /** @var list<string> */
        public array $requestedScopes,
        public ?string $calendarId,
        public bool $tokenAcquired,
        /** @var list<string> */
        public array $allowedConferenceTypes,
        public ?string $error,
    ) {}
}
