<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use App\Booking\Enums\MeetingAttendanceEventType;
use App\Lessons\Support\AttendanceMetadataSanitizer;
use Carbon\CarbonImmutable;

/**
 * One normalized provider attendance event — the only shape allowed to
 * cross from a provider adapter into the ingestion layer. Adapters
 * construct it via fromProvider(), which hashes the raw participant
 * reference (often an email at the provider) immediately and sanitizes
 * metadata, so tokens, emails, phone numbers, meeting links,
 * transcripts, and raw payloads cannot be persisted or logged
 * downstream. Timestamps are normalized to UTC.
 */
final readonly class ProviderAttendanceEvent
{
    /** @param array<string, scalar> $metadata already sanitized — use fromProvider() outside this class */
    private function __construct(
        public string $provider,
        public string $providerEventId,
        public string $meetingReference,
        /** sha256 of the provider participant reference — raw refs never leave the adapter. */
        public string $participantKey,
        public MeetingAttendanceEventType $eventType,
        public CarbonImmutable $occurredAt,
        /** Untrusted normalized role claim ('student'|'instructor') — corroboration only, never resolution. */
        public ?string $roleHint,
        public ?CarbonImmutable $joinedAt,
        public ?CarbonImmutable $leftAt,
        public ?int $attendedSeconds,
        public array $metadata,
    ) {}

    /** @param array<string, mixed> $metadata raw adapter metadata — sanitized here */
    public static function fromProvider(
        string $provider,
        string $providerEventId,
        string $meetingReference,
        string $participantReference,
        MeetingAttendanceEventType $eventType,
        CarbonImmutable $occurredAt,
        ?string $roleHint = null,
        ?CarbonImmutable $joinedAt = null,
        ?CarbonImmutable $leftAt = null,
        ?int $attendedSeconds = null,
        array $metadata = [],
    ): self {
        return new self(
            provider: $provider,
            providerEventId: $providerEventId,
            meetingReference: $meetingReference,
            participantKey: self::keyFor($participantReference),
            eventType: $eventType,
            occurredAt: $occurredAt->utc(),
            roleHint: $roleHint,
            joinedAt: $joinedAt?->utc(),
            leftAt: $leftAt?->utc(),
            attendedSeconds: $attendedSeconds,
            metadata: AttendanceMetadataSanitizer::sanitize($metadata),
        );
    }

    public static function keyFor(string $participantReference): string
    {
        return hash('sha256', $participantReference);
    }

    /** Storable (already-sanitized, hash-only) representation for the operational event log. */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'provider_event_id' => $this->providerEventId,
            'meeting_reference' => $this->meetingReference,
            'participant_key' => $this->participantKey,
            'role_hint' => $this->roleHint,
            'event_type' => $this->eventType->value,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'joined_at' => $this->joinedAt?->toIso8601String(),
            'left_at' => $this->leftAt?->toIso8601String(),
            'attended_seconds' => $this->attendedSeconds,
            'metadata' => $this->metadata,
        ];
    }

    /** @param array<string, mixed> $data produced by toArray() */
    public static function fromArray(array $data): self
    {
        return new self(
            provider: $data['provider'],
            providerEventId: $data['provider_event_id'],
            meetingReference: $data['meeting_reference'],
            participantKey: $data['participant_key'],
            eventType: MeetingAttendanceEventType::from($data['event_type']),
            occurredAt: CarbonImmutable::parse($data['occurred_at'])->utc(),
            roleHint: $data['role_hint'] ?? null,
            joinedAt: isset($data['joined_at']) ? CarbonImmutable::parse($data['joined_at'])->utc() : null,
            leftAt: isset($data['left_at']) ? CarbonImmutable::parse($data['left_at'])->utc() : null,
            attendedSeconds: $data['attended_seconds'] ?? null,
            metadata: AttendanceMetadataSanitizer::sanitize($data['metadata'] ?? []),
        );
    }
}
