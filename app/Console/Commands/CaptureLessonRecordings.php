<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Booking\Registry\MeetingProviderRegistry;
use App\Booking\Services\RecordingService;
use App\Booking\Services\RecordingStagingArea;
use App\Models\Recording;
use App\Settings\MeetingSettings;
use Illuminate\Console\Command;
use Throwable;

/**
 * Eventual correctness for the recording pipeline. The queued
 * CaptureLessonRecordingJob provides low latency; this sweep provides
 * the guarantee — a recording is never lost because a worker restarted,
 * the queue was down, or the meeting provider was still processing the
 * file when the job ran.
 *
 * Three bounded jobs, in order:
 *
 *   1. reclaim rows abandoned mid-transfer by a crashed worker;
 *   2. retry every Pending/Stored recording inside the configured
 *      age and attempt window;
 *   3. purge staged temp files a crashed run left on disk.
 *
 * Bounded on every axis: a date window (never the whole table), an
 * attempt budget, and a batch size, with lazyById() paging. One
 * recording's failure never stops the batch — ingestion records its
 * own outcome on the row.
 */
final class CaptureLessonRecordings extends Command
{
    protected $signature = 'recordings:capture';

    protected $description = 'Reconcile pending lesson recordings against their meeting provider and storage backend';

    public function handle(
        RecordingService $recordings,
        MeetingProviderRegistry $registry,
        RecordingStagingArea $staging,
        MeetingSettings $settings,
    ): int {
        $batchSize = max(1, $settings->recording_capture_batch_size);

        $reclaimed = $recordings->reclaimStalledTransfers($settings->recording_transfer_stale_minutes, $batchSize);

        $endedBefore = now()->subMinutes(max(0, $settings->recording_capture_delay_minutes));
        $endedAfter = now()->subHours(max(1, $settings->recording_capture_max_age_hours));

        $due = Recording::query()
            ->dueForCapture()
            ->where('capture_attempts', '<', max(1, $settings->recording_capture_max_attempts))
            ->whereHas('bookingMeeting', fn ($query) => $query->whereBetween('ends_at', [$endedAfter, $endedBefore]))
            ->with('bookingMeeting')
            ->lazyById($batchSize);

        $processed = 0;

        foreach ($due as $recording) {
            if (! $registry->has($recording->provider)) {
                continue;
            }

            try {
                $recordings->capture($recording, $registry->get($recording->provider));
                $processed++;
            } catch (Throwable $e) {
                $this->error(sprintf('Recording %s: %s', $recording->getKey(), $e->getMessage()));
            }
        }

        $purged = $staging->purgeStale();

        $this->info(sprintf(
            'Processed %d pending recording(s); reclaimed %d stalled transfer(s); purged %d stale staged file(s).',
            $processed,
            $reclaimed,
            $purged,
        ));

        return self::SUCCESS;
    }
}
