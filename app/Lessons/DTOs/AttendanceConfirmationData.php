<?php

declare(strict_types=1);

namespace App\Lessons\DTOs;

use Carbon\CarbonImmutable;

/**
 * A participant's own attendance claim. At least one of a claimed join
 * time or claimed attended minutes is required — a bare "I attended"
 * never invents a duration. The participant role and lesson are NEVER
 * taken from here; they are derived from the authenticated user and
 * the lesson's stored participants.
 */
final readonly class AttendanceConfirmationData
{
    public function __construct(
        public ?CarbonImmutable $claimedJoinedAt = null,
        public ?CarbonImmutable $claimedLeftAt = null,
        public ?int $claimedAttendedMinutes = null,
        public ?string $notes = null,
    ) {}

    public function hasClaim(): bool
    {
        return $this->claimedJoinedAt !== null || $this->claimedAttendedMinutes !== null;
    }

    /** Stable dedup basis — identical resubmissions hash identically. */
    public function fingerprintBasis(): string
    {
        return implode('|', [
            $this->claimedJoinedAt?->utc()->toIso8601String() ?? '-',
            $this->claimedLeftAt?->utc()->toIso8601String() ?? '-',
            $this->claimedAttendedMinutes ?? '-',
        ]);
    }
}
