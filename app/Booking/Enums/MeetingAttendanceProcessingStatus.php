<?php

declare(strict_types=1);

namespace App\Booking\Enums;

/** Lifecycle of a received meeting-attendance provider event (webhook or sync envelope). */
enum MeetingAttendanceProcessingStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Duplicate = 'duplicate';

    /** Needs a human: unknown meeting, unresolved/ambiguous participant, role contradiction. */
    case Review = 'review';

    /** Safely dropped: cancelled booking or otherwise ineligible lesson. */
    case Ignored = 'ignored';

    /** Retryable — provider unavailable or an unexpected error, within the retry budget. */
    case FailedTransient = 'failed_transient';

    /** Retry budget exhausted — needs operational attention, never retried automatically. */
    case FailedPermanent = 'failed_permanent';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Processed => 'Processed',
            self::Duplicate => 'Duplicate',
            self::Review => 'Needs Review',
            self::Ignored => 'Ignored',
            self::FailedTransient => 'Failed (retryable)',
            self::FailedPermanent => 'Failed (permanent)',
        };
    }

    /** Whether processing may run (again) for a row in this state. */
    public function isProcessable(): bool
    {
        return match ($this) {
            self::Received, self::FailedTransient => true,
            default => false,
        };
    }
}
