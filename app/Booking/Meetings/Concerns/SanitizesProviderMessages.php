<?php

declare(strict_types=1);

namespace App\Booking\Meetings\Concerns;

use Illuminate\Support\Str;

/**
 * Redacts anything token/key-shaped from a provider error message
 * before it is persisted as booking_meetings.failure_reason, logged,
 * or shown to an admin. Length-capped to the failure_reason column.
 */
trait SanitizesProviderMessages
{
    private function sanitize(string $message): string
    {
        return Str::limit(
            preg_replace('/[A-Za-z0-9_\-]{20,}/', '[redacted]', $message) ?? 'Unknown meeting provider error.',
            500,
        );
    }
}
