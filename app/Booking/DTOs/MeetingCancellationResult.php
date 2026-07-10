<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use App\Booking\Enums\MeetingStatus;

/** Result of MeetingProviderInterface::cancelMeeting(). */
final readonly class MeetingCancellationResult
{
    public function __construct(
        public MeetingStatus $status,
        public ?string $failureReason = null,
        /** @var array<string, mixed> */
        public array $metadata = [],
    ) {}
}
