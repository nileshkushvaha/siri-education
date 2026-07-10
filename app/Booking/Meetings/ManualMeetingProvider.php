<?php

declare(strict_types=1);

namespace App\Booking\Meetings;

use App\Booking\Contracts\MeetingProviderInterface;
use App\Booking\DTOs\MeetingCancellationResult;
use App\Booking\DTOs\MeetingCreationContext;
use App\Booking\DTOs\MeetingCreationResult;
use App\Booking\DTOs\MeetingUpdateContext;
use App\Booking\Enums\MeetingStatus;
use App\Booking\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\BookingMeeting;

/**
 * Admin-entered meeting links — no external API, no credentials.
 *
 * `createMeeting()` without a join URL yet (the automatic-trigger path,
 * when MeetingSettings::default_provider = 'manual') produces a
 * `pending` placeholder row for the admin to fill in via the
 * "Create/Update Meeting" action; supplying a join URL up front (the
 * admin's own action) produces a `created` row immediately.
 * `providerLabel` (e.g. "zoom_manual", "google_meet_manual") describes
 * what kind of link the admin pasted — it is never the provider driver
 * key, only descriptive metadata.
 */
final class ManualMeetingProvider implements MeetingProviderInterface
{
    public const string KEY = 'manual';

    public function key(): string
    {
        return self::KEY;
    }

    public function createMeeting(Booking $booking, MeetingCreationContext $context): MeetingCreationResult
    {
        $this->assertValidUrl($context->joinUrl);

        return new MeetingCreationResult(
            provider: self::KEY,
            providerMeetingId: null,
            providerEventId: null,
            joinUrl: $context->joinUrl,
            hostUrl: null,
            password: $context->password,
            startsAt: $context->startsAt ?? $booking->starts_at,
            endsAt: $context->endsAt ?? $booking->ends_at,
            timezone: $context->timezone ?? $booking->timezone,
            status: $context->joinUrl !== null ? MeetingStatus::Created : MeetingStatus::Pending,
            metadata: array_filter(['manual_label' => $context->providerLabel]),
        );
    }

    public function updateMeeting(BookingMeeting $meeting, MeetingUpdateContext $context): MeetingCreationResult
    {
        $this->assertValidUrl($context->joinUrl);

        $joinUrl = $context->joinUrl ?? $meeting->join_url;

        return new MeetingCreationResult(
            provider: self::KEY,
            providerMeetingId: $meeting->provider_meeting_id,
            providerEventId: $meeting->provider_event_id,
            joinUrl: $joinUrl,
            hostUrl: $meeting->host_url,
            password: $context->password ?? $meeting->password,
            startsAt: $context->startsAt ?? $meeting->starts_at,
            endsAt: $context->endsAt ?? $meeting->ends_at,
            timezone: $context->timezone ?? $meeting->timezone,
            status: $joinUrl !== null ? MeetingStatus::Created : MeetingStatus::Pending,
            metadata: array_filter(['manual_label' => $context->providerLabel ?? ($meeting->metadata['manual_label'] ?? null)]),
        );
    }

    public function cancelMeeting(BookingMeeting $meeting): MeetingCancellationResult
    {
        return new MeetingCancellationResult(status: MeetingStatus::Cancelled);
    }

    /** No credentials to validate — always usable; MeetingSettings::manual_provider_enabled gates it in the resolver. */
    public function isConfigured(): bool
    {
        return true;
    }

    /** @throws BookingException when a non-empty join URL is not a well-formed URL. */
    private function assertValidUrl(?string $url): void
    {
        if ($url !== null && filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new BookingException('Meeting join URL must be a valid URL.');
        }
    }
}
