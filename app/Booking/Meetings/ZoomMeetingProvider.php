<?php

declare(strict_types=1);

namespace App\Booking\Meetings;

use App\Booking\Contracts\MeetingProviderInterface;
use App\Booking\Contracts\ZoomMeetingClient;
use App\Booking\DTOs\MeetingCancellationResult;
use App\Booking\DTOs\MeetingCreationContext;
use App\Booking\DTOs\MeetingCreationResult;
use App\Booking\DTOs\MeetingUpdateContext;
use App\Booking\Enums\MeetingStatus;
use App\Booking\Exceptions\BookingException;
use App\Booking\Meetings\Concerns\BuildsSafeMeetingContent;
use App\Booking\Meetings\Concerns\SanitizesProviderMessages;
use App\Booking\Services\RecordingEligibilityResolver;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Settings\MeetingSettings;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Schedules a Zoom meeting (Server-to-Server OAuth, platform-owned
 * host account) for a confirmed booking. All Zoom HTTP traffic lives
 * behind ZoomMeetingClient — this class never sees a token or a raw
 * API response, only the client's sanitized six-field array.
 *
 * The meeting is created under MeetingSettings::zoom_host_user_id (or
 * zoom_host_email) — the platform's Zoom user, not the instructor's;
 * per-instructor Zoom accounts are out of scope this phase. start_url
 * is persisted as BookingMeeting.host_url (hidden from serialization,
 * never shown to students); the join password rides the same hidden
 * password column the other providers use.
 */
final class ZoomMeetingProvider implements MeetingProviderInterface
{
    use BuildsSafeMeetingContent;
    use SanitizesProviderMessages;

    public const string KEY = 'zoom';

    /** Zoom meeting type 2 = scheduled (never an instant meeting). */
    private const int TYPE_SCHEDULED = 2;

    public function __construct(
        private readonly ZoomMeetingClient $client,
        private readonly MeetingSettings $settings,
        private readonly RecordingEligibilityResolver $recordingEligibility,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function isConfigured(): bool
    {
        return $this->settings->zoom_enabled
            && filled($this->settings->zoom_account_id)
            && filled($this->settings->zoom_client_id)
            && $this->settings->decryptedZoomClientSecret() !== null
            && (filled($this->settings->zoom_host_user_id) || filled($this->settings->zoom_host_email));
    }

    public function createMeeting(Booking $booking, MeetingCreationContext $context): MeetingCreationResult
    {
        $startsAt = $context->startsAt ?? $booking->starts_at;
        $endsAt = $context->endsAt ?? $booking->ends_at;
        $timezone = $context->timezone ?? $this->timezoneFor($booking);

        try {
            $meeting = $this->client->createMeeting(
                $this->hostUser(),
                $this->meetingPayload($booking, $startsAt, $endsAt, $timezone),
            );
        } catch (Throwable $e) {
            throw new BookingException($this->sanitize($e->getMessage()));
        }

        return $this->resultFromMeeting($startsAt, $endsAt, $timezone, $meeting);
    }

    public function updateMeeting(BookingMeeting $meeting, MeetingUpdateContext $context): MeetingCreationResult
    {
        $booking = $meeting->booking;
        $startsAt = $context->startsAt ?? $meeting->starts_at;
        $endsAt = $context->endsAt ?? $meeting->ends_at;
        $timezone = $context->timezone ?? $meeting->timezone;

        try {
            $fresh = $meeting->provider_meeting_id !== null
                ? $this->client->updateMeeting($meeting->provider_meeting_id, $this->meetingPayload($booking, $startsAt, $endsAt, $timezone))
                : $this->client->createMeeting($this->hostUser(), $this->meetingPayload($booking, $startsAt, $endsAt, $timezone));
        } catch (Throwable $e) {
            throw new BookingException($this->sanitize($e->getMessage()));
        }

        return $this->resultFromMeeting($startsAt, $endsAt, $timezone, $fresh);
    }

    public function cancelMeeting(BookingMeeting $meeting): MeetingCancellationResult
    {
        if ($meeting->provider_meeting_id === null) {
            return new MeetingCancellationResult(status: MeetingStatus::Cancelled);
        }

        try {
            $this->client->deleteMeeting($meeting->provider_meeting_id);
        } catch (Throwable $e) {
            return new MeetingCancellationResult(status: MeetingStatus::Failed, failureReason: $this->sanitize($e->getMessage()));
        }

        return new MeetingCancellationResult(status: MeetingStatus::Cancelled);
    }

    /** @param  array{id: string, join_url: ?string, start_url: ?string, password: ?string, timezone: ?string, status: ?string}  $meeting */
    private function resultFromMeeting(
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        string $timezone,
        array $meeting,
    ): MeetingCreationResult {
        // Unlike Google's async conference creation, a successful Zoom
        // create/update always carries join_url — its absence means the
        // response wasn't a meeting at all.
        if (blank($meeting['join_url'])) {
            throw new BookingException('Zoom did not return a join URL for the meeting.');
        }

        return new MeetingCreationResult(
            provider: self::KEY,
            providerMeetingId: $meeting['id'] !== '' ? $meeting['id'] : null,
            providerEventId: null,
            joinUrl: $meeting['join_url'],
            hostUrl: $meeting['start_url'],
            password: $meeting['password'],
            startsAt: $startsAt,
            endsAt: $endsAt,
            timezone: $timezone,
            status: MeetingStatus::Created,
            metadata: array_filter(['zoom_status' => $meeting['status']]),
        );
    }

    /** @return array<string, mixed> */
    private function meetingPayload(Booking $booking, CarbonImmutable $startsAt, CarbonImmutable $endsAt, string $timezone): array
    {
        return [
            'topic' => $this->safeTitle($booking),
            'type' => self::TYPE_SCHEDULED,
            'start_time' => $startsAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'duration' => max(1, (int) $startsAt->diffInMinutes($endsAt)),
            'timezone' => $timezone,
            'agenda' => $this->safeDescription($booking),
            'settings' => [
                'join_before_host' => false,
                'waiting_room' => true,
                'mute_upon_entry' => true,
                // Driven by the full eligibility chain, not a
                // hardcode. This provider never declares
                // MeetingRecordingProviderInterface, so
                // "provider_capability_missing" always fails the check
                // today — auto_recording stays 'none' in practice until
                // a future phase adds real Zoom recording-fetch support,
                // at which point this line needs no further change.
                'auto_recording' => $this->recordingEligibility->evaluate($booking, $this)->eligible ? 'cloud' : 'none',
                'host_video' => true,
                'participant_video' => false,
            ],
        ];
    }

    private function timezoneFor(Booking $booking): string
    {
        return $booking->timezone
            ?? $this->settings->zoom_default_timezone
            ?? (string) config('app.timezone', 'UTC');
    }

    private function hostUser(): string
    {
        return $this->settings->zoom_host_user_id
            ?? $this->settings->zoom_host_email
            ?? throw new BookingException('No Zoom host user is configured.');
    }
}
