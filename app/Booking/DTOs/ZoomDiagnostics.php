<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

/**
 * Non-secret runtime diagnostics for the Zoom integration — surfaced by
 * ZoomConfigurationService's live "Validate Zoom Configuration" check.
 * Every field here is safe to show an admin or write to a notification:
 * no client secret, access token, or raw API response ever belongs on
 * this DTO. Mirrors GoogleCalendarDiagnostics.
 */
final readonly class ZoomDiagnostics
{
    public function __construct(
        public ?string $accountId,
        public ?string $clientId,
        public ?string $hostUser,
        public bool $tokenAcquired,
        public bool $meetingCreationVerified,
        public ?string $error,
    ) {}
}
