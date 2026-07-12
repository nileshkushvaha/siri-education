<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\ProviderAttendanceEvent;
use App\Booking\DTOs\ProviderAttendanceWebhook;
use App\Booking\Exceptions\AttendanceSyncUnavailableException;
use App\Booking\Exceptions\InvalidAttendanceWebhookException;
use App\Models\BookingMeeting;
use Illuminate\Http\Request;

/**
 * Optional attendance capability for meeting providers. A provider that
 * can report who attended implements this alongside
 * MeetingProviderInterface; the ingestion layer discovers the
 * capability via instanceof, so existing providers need no changes.
 * Adapters normalize everything into ProviderAttendance* DTOs — raw
 * provider payloads never cross into the Lesson domain.
 */
interface MeetingAttendanceProviderInterface
{
    /** Must match MeetingProviderInterface::key(). */
    public function key(): string;

    /** Whether the provider pushes attendance webhooks this platform can verify. */
    public function supportsAttendanceWebhooks(): bool;

    /** Whether attendance can be pulled from the provider's API (meetings:sync-attendance). */
    public function supportsAttendanceSync(): bool;

    /**
     * Verify the webhook's authenticity BEFORE any parsing. Never
     * throws — an unverifiable request is simply not authentic.
     */
    public function verifyAttendanceWebhookSignature(Request $request): bool;

    /**
     * Normalize a verified webhook. Malformed timestamps, unknown event
     * types, and structurally invalid payloads must throw — never guess.
     *
     * @throws InvalidAttendanceWebhookException
     */
    public function parseAttendanceWebhook(Request $request): ProviderAttendanceWebhook;

    /**
     * Pull the meeting's participant sessions from the provider API.
     *
     * @return list<ProviderAttendanceEvent>
     *
     * @throws AttendanceSyncUnavailableException transient — the meeting stays retryable
     */
    public function fetchAttendance(BookingMeeting $meeting): array;
}
