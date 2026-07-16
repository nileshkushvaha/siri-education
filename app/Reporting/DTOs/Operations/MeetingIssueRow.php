<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Operations;

use Carbon\CarbonImmutable;

/** One row of the "meeting issues" actionable table — creation failures and confirmed bookings missing a meeting. */
final readonly class MeetingIssueRow
{
    public function __construct(
        public string $bookingId,
        public string $bookingReference,
        public CarbonImmutable $scheduledAtUtc,
        public string $instructorLabel,
        public string $studentLabel,
        public string $issueLabel,
        public ?string $meetingStatusLabel,
        public ?string $bookingViewUrl,
    ) {}
}
