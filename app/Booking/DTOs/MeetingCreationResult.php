<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use App\Booking\Enums\MeetingStatus;
use Carbon\CarbonImmutable;

/**
 * Provider-neutral result of creating/updating a meeting for a confirmed
 * booking. Never carries raw provider payloads or secrets — metadata
 * must be pre-sanitized by the provider before it reaches this DTO.
 * `joinUrl` is nullable: Google's conference creation is asynchronous,
 * so a `Pending` status with no join_url yet is a valid, safe result.
 */
final readonly class MeetingCreationResult
{
    public function __construct(
        public string $provider,
        public ?string $providerMeetingId,
        public ?string $providerEventId,
        public ?string $joinUrl,
        public ?string $hostUrl,
        public ?string $password,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public string $timezone,
        public MeetingStatus $status,
        /** @var array<string, mixed> */
        public array $metadata = [],
    ) {}
}
