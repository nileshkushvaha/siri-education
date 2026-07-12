<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Models\MeetingAttendanceProviderEvent;

/**
 * Processes one received provider event (webhook envelope or sync
 * pull): locates the meeting/lesson, resolves participants against
 * stored booking data, and feeds accepted evidence through
 * LessonAttendanceService → RecordAttendanceAction — never writing
 * attendance aggregates directly.
 */
interface MeetingAttendanceIngestionServiceInterface
{
    /**
     * Idempotent and concurrency-safe: the row is claimed under a lock,
     * already-settled rows are left alone. Transient failures rethrow
     * (so queued callers back off and retry) after marking the row;
     * exhausted retries settle as failed_permanent without throwing.
     */
    public function process(MeetingAttendanceProviderEvent $event): MeetingAttendanceProviderEvent;
}
