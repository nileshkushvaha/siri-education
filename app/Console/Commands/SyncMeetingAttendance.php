<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Booking\Contracts\MeetingAttendanceSyncServiceInterface;
use Illuminate\Console\Command;

/**
 * Phase 17C attendance reconciliation: pulls participant sessions for
 * eligible recently ended meetings from attendance-capable providers
 * and feeds them through the evidence pipeline. Idempotent — settled
 * meetings are skipped unless --force. A no-op while
 * meeting.attendance_sync_enabled is off.
 */
class SyncMeetingAttendance extends Command
{
    protected $signature = 'meetings:sync-attendance {--force : Re-fetch meetings already settled}';

    protected $description = 'Fetch and reconcile meeting attendance from providers into the lesson attendance evidence layer';

    public function handle(MeetingAttendanceSyncServiceInterface $sync): int
    {
        $settled = $sync->sync(force: (bool) $this->option('force'));

        $this->info(sprintf('Settled attendance for %d meeting(s).', $settled));

        return self::SUCCESS;
    }
}
