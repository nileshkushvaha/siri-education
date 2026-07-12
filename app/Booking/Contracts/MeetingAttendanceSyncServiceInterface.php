<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

/** Pull-based attendance reconciliation (meetings:sync-attendance). */
interface MeetingAttendanceSyncServiceInterface
{
    /**
     * Fetch attendance for eligible recently ended meetings through the
     * active provider adapters and feed it into the ingestion pipeline.
     * Idempotent (settled meetings are skipped unless $force), batched,
     * per-meeting failure isolated, bounded retries. Returns the number
     * of meetings settled this run. No-op while
     * meeting.attendance_sync_enabled is off.
     */
    public function sync(bool $force = false): int;
}
