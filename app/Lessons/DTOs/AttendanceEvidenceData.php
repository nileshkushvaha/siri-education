<?php

declare(strict_types=1);

namespace App\Lessons\DTOs;

use App\Lessons\Enums\AttendanceSource;
use App\Lessons\Enums\LessonParticipant;
use Carbon\CarbonImmutable;

/**
 * One piece of attendance evidence: a provider join/leave notification,
 * a sync row, or a manual confirmation. Timestamps are normalized to
 * UTC on construction; metadata must already exclude sensitive data
 * (RecordAttendanceAction re-sanitizes defensively).
 */
final readonly class AttendanceEvidenceData
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public LessonParticipant $participant,
        public AttendanceSource $source,
        public ?CarbonImmutable $joinedAt = null,
        public ?CarbonImmutable $leftAt = null,
        public ?int $attendedSeconds = null,
        public ?string $providerReference = null,
        public ?string $providerEventId = null,
        public array $metadata = [],
        public bool $technicalIssueReported = false,
    ) {}

    public function joinedAtUtc(): ?CarbonImmutable
    {
        return $this->joinedAt?->utc();
    }

    public function leftAtUtc(): ?CarbonImmutable
    {
        return $this->leftAt?->utc();
    }

    /**
     * Stable dedup key: the provider event id when the source supplies
     * one, otherwise the normalized evidence tuple — replaying the same
     * webhook or repeating the same command always hashes identically.
     */
    public function fingerprint(): string
    {
        $basis = $this->providerEventId !== null && $this->providerEventId !== ''
            ? implode('|', [$this->source->value, 'event', $this->providerEventId])
            : implode('|', [
                $this->source->value,
                $this->participant->value,
                $this->joinedAtUtc()?->toIso8601String() ?? '-',
                $this->leftAtUtc()?->toIso8601String() ?? '-',
                $this->attendedSeconds ?? '-',
                $this->providerReference ?? '-',
            ]);

        return hash('sha256', $basis);
    }
}
