<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Booking\Services\RecordingService;
use App\Settings\MeetingSettings;
use Illuminate\Console\Command;

/**
 * GAP-028 requirement #7 — bounded retention cleanup: deletes the
 * media FILE for every Available recording past its expires_at, but
 * keeps the metadata row (status becomes Expired) for historical/audit
 * evidence. Bounded per run via meeting.recording_expiry_batch_size —
 * safe to run on a tight schedule without ever sweeping the whole table.
 */
final class ExpireLessonRecordings extends Command
{
    protected $signature = 'recordings:expire';

    protected $description = 'Delete media for recordings past their retention period, preserving metadata';

    public function handle(RecordingService $recordings, MeetingSettings $settings): int
    {
        $expired = $recordings->expireDueRecordings($settings->recording_expiry_batch_size);

        $this->info(sprintf('Expired %d recording(s).', $expired));

        return self::SUCCESS;
    }
}
