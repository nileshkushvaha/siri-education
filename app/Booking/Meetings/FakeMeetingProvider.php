<?php

declare(strict_types=1);

namespace App\Booking\Meetings;

use App\Booking\Contracts\MeetingAttendanceProviderInterface;
use App\Booking\Contracts\MeetingProviderInterface;
use App\Booking\Contracts\MeetingRecordingProviderInterface;
use App\Booking\DTOs\MeetingCancellationResult;
use App\Booking\DTOs\MeetingCreationContext;
use App\Booking\DTOs\MeetingCreationResult;
use App\Booking\DTOs\MeetingUpdateContext;
use App\Booking\DTOs\ProviderAttendanceEvent;
use App\Booking\DTOs\ProviderAttendanceWebhook;
use App\Booking\DTOs\ProviderRecordingResult;
use App\Booking\Enums\MeetingAttendanceEventType;
use App\Booking\Enums\MeetingStatus;
use App\Booking\Exceptions\AttendanceSyncUnavailableException;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\InvalidAttendanceWebhookException;
use App\Models\Booking;
use App\Models\BookingMeeting;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

/**
 * Test/dev-only meeting provider with full attendance simulation:
 * signed webhooks (HMAC-SHA256 over the raw body), duplicate and
 * out-of-order events, attendance sync, API failure, unknown
 * participants, and late evidence. Registered ONLY in the testing
 * environment (BookingServiceProvider) — production keeps the original
 * "no fake meeting provider" decision. Moves no data anywhere.
 *
 * GAP-028: also the only provider implementing
 * MeetingRecordingProviderInterface — real providers (Zoom/Google Meet/
 * Manual) all decline recording support until a future phase adds it
 * for real (SRS §12.31 "where the active provider supports it").
 */
final class FakeMeetingProvider implements MeetingAttendanceProviderInterface, MeetingProviderInterface, MeetingRecordingProviderInterface
{
    public const string KEY = 'fake';

    public const string WEBHOOK_SECRET = 'fake-attendance-webhook-secret';

    public const string SIGNATURE_HEADER = 'X-Fake-Signature';

    /** @var list<ProviderAttendanceEvent> next fetchAttendance() result */
    public static array $syncEvents = [];

    public static bool $failNextSync = false;

    public static bool $supportsSync = true;

    public static bool $supportsWebhooks = true;

    public static bool $supportsRecording = true;

    /** Null = "not ready yet" (transient) — the default, realistic state. */
    public static ?ProviderRecordingResult $nextRecordingResult = null;

    public static bool $failNextRecordingFetch = false;

    public static function reset(): void
    {
        self::$syncEvents = [];
        self::$failNextSync = false;
        self::$supportsSync = true;
        self::$supportsWebhooks = true;
        self::$supportsRecording = true;
        self::$nextRecordingResult = null;
        self::$failNextRecordingFetch = false;
    }

    public static function sign(string $body): string
    {
        return hash_hmac('sha256', $body, self::WEBHOOK_SECRET);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    // ── MeetingProviderInterface (minimal — meetings are factory-made in tests) ──

    public function createMeeting(Booking $booking, MeetingCreationContext $context): MeetingCreationResult
    {
        return new MeetingCreationResult(
            provider: self::KEY,
            providerMeetingId: 'fake-'.$booking->id,
            providerEventId: null,
            joinUrl: 'https://fake.test/join/'.$booking->id,
            hostUrl: null,
            password: null,
            startsAt: CarbonImmutable::parse($booking->starts_at),
            endsAt: CarbonImmutable::parse($booking->ends_at),
            timezone: 'UTC',
            status: MeetingStatus::Created,
        );
    }

    public function updateMeeting(BookingMeeting $meeting, MeetingUpdateContext $context): MeetingCreationResult
    {
        return new MeetingCreationResult(
            provider: self::KEY,
            providerMeetingId: $meeting->provider_meeting_id,
            providerEventId: $meeting->provider_event_id,
            joinUrl: $meeting->join_url,
            hostUrl: null,
            password: null,
            startsAt: $meeting->starts_at,
            endsAt: $meeting->ends_at,
            timezone: $meeting->timezone ?? 'UTC',
            status: MeetingStatus::Created,
        );
    }

    public function cancelMeeting(BookingMeeting $meeting): MeetingCancellationResult
    {
        return new MeetingCancellationResult(MeetingStatus::Cancelled);
    }

    // ── MeetingAttendanceProviderInterface ────────────────────────────────

    public function supportsAttendanceWebhooks(): bool
    {
        return self::$supportsWebhooks;
    }

    public function supportsAttendanceSync(): bool
    {
        return self::$supportsSync;
    }

    public function verifyAttendanceWebhookSignature(Request $request): bool
    {
        $signature = (string) $request->header(self::SIGNATURE_HEADER, '');

        return $signature !== '' && hash_equals(self::sign($request->getContent()), $signature);
    }

    public function parseAttendanceWebhook(Request $request): ProviderAttendanceWebhook
    {
        $payload = $request->json()->all();

        $eventId = $payload['event_id'] ?? null;
        $meetingRef = $payload['meeting_ref'] ?? null;
        $events = $payload['events'] ?? null;

        if (! is_string($eventId) || $eventId === '' || ! is_string($meetingRef) || ! is_array($events)) {
            throw new InvalidAttendanceWebhookException('Missing event_id, meeting_ref, or events.');
        }

        return new ProviderAttendanceWebhook(
            provider: self::KEY,
            providerEventId: $eventId,
            meetingReference: $meetingRef,
            events: array_values(array_map(
                fn (array $event): ProviderAttendanceEvent => $this->normalizeEvent($meetingRef, $event),
                $events,
            )),
        );
    }

    public function fetchAttendance(BookingMeeting $meeting): array
    {
        if (self::$failNextSync) {
            self::$failNextSync = false;

            throw new AttendanceSyncUnavailableException('Fake provider attendance API is unavailable.');
        }

        return self::$syncEvents;
    }

    /** Builder for tests and for the fake sync fixture. */
    public static function makeEvent(
        string $eventId,
        string $meetingRef,
        string $participantRef,
        string $type,
        string $occurredAt,
        ?string $roleHint = null,
        ?string $joinedAt = null,
        ?string $leftAt = null,
        ?int $seconds = null,
        array $metadata = [],
    ): ProviderAttendanceEvent {
        return ProviderAttendanceEvent::fromProvider(
            provider: self::KEY,
            providerEventId: $eventId,
            meetingReference: $meetingRef,
            participantReference: $participantRef,
            eventType: MeetingAttendanceEventType::from($type),
            occurredAt: CarbonImmutable::parse($occurredAt)->utc(),
            roleHint: $roleHint,
            joinedAt: $joinedAt !== null ? CarbonImmutable::parse($joinedAt)->utc() : null,
            leftAt: $leftAt !== null ? CarbonImmutable::parse($leftAt)->utc() : null,
            attendedSeconds: $seconds,
            metadata: $metadata,
        );
    }

    /** @param array<string, mixed> $event */
    private function normalizeEvent(string $meetingRef, array $event): ProviderAttendanceEvent
    {
        $type = MeetingAttendanceEventType::tryFrom((string) ($event['type'] ?? ''))
            ?? throw new InvalidAttendanceWebhookException('Unknown attendance event type.');

        $participantRef = $event['participant_ref'] ?? null;
        $eventId = $event['id'] ?? null;

        if (! is_string($participantRef) || $participantRef === '' || ! is_string($eventId) || $eventId === '') {
            throw new InvalidAttendanceWebhookException('Missing participant reference or event id.');
        }

        return ProviderAttendanceEvent::fromProvider(
            provider: self::KEY,
            providerEventId: $eventId,
            meetingReference: $meetingRef,
            participantReference: $participantRef,
            eventType: $type,
            occurredAt: $this->parseTimestamp($event['occurred_at'] ?? null, required: true),
            roleHint: isset($event['role']) && is_string($event['role']) ? $event['role'] : null,
            joinedAt: $this->parseTimestamp($event['joined_at'] ?? null),
            leftAt: $this->parseTimestamp($event['left_at'] ?? null),
            attendedSeconds: isset($event['seconds']) && is_int($event['seconds']) ? $event['seconds'] : null,
            metadata: is_array($event['meta'] ?? null) ? $event['meta'] : [],
        );
    }

    private function parseTimestamp(mixed $value, bool $required = false): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new InvalidAttendanceWebhookException('Missing required timestamp.');
            }

            return null;
        }

        if (! is_string($value)) {
            throw new InvalidAttendanceWebhookException('Malformed timestamp.');
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            throw new InvalidAttendanceWebhookException('Malformed timestamp.');
        }
    }

    // ── MeetingRecordingProviderInterface ─────────────────────────────

    public function supportsRecording(): bool
    {
        return self::$supportsRecording;
    }

    public function fetchRecording(BookingMeeting $meeting): ?ProviderRecordingResult
    {
        if (self::$failNextRecordingFetch) {
            self::$failNextRecordingFetch = false;

            throw new BookingException('Fake provider recording fetch failed.');
        }

        return self::$nextRecordingResult;
    }
}
