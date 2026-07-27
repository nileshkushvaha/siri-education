<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Booking\Registry\MeetingProviderRegistry;
use App\Booking\Services\RecordingService;
use App\Models\Recording;
use App\Settings\MeetingSettings;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reconciliation sweep — complements the queued
 * CaptureLessonRecordingJob dispatched at meeting-creation time. Picks
 * up any Pending recording the delayed job missed (worker restart,
 * queue outage) or that is still waiting on a transient provider
 * retry, within the same bounded age/attempt window
 * RecordingService::capture() itself enforces. One recording's failure
 * never stops the batch.
 */
final class CaptureLessonRecordings extends Command
{
    protected $signature = 'recordings:capture';

    protected $description = 'Reconcile pending lesson recordings against their meeting provider';

    public function handle(RecordingService $recordings, MeetingProviderRegistry $registry, MeetingSettings $settings): int
    {
        $endedBefore = now()->subMinutes(max(0, $settings->recording_capture_delay_minutes));
        $endedAfter = now()->subHours(max(1, $settings->recording_capture_max_age_hours));

        $due = Recording::query()
            ->dueForCapture()
            ->where('capture_attempts', '<', max(1, $settings->recording_capture_max_attempts))
            ->whereHas('bookingMeeting', fn ($query) => $query->whereBetween('ends_at', [$endedAfter, $endedBefore]))
            ->with('bookingMeeting')
            ->lazyById(max(1, $settings->recording_capture_batch_size));

        $processed = 0;

        foreach ($due as $recording) {
            if (! $registry->has($recording->provider)) {
                continue;
            }

            try {
                $recordings->capture($recording, $registry->get($recording->provider));
                $processed++;
            } catch (Throwable $e) {
                $this->error(sprintf('Recording %s: %s', $recording->id, $e->getMessage()));
            }
        }

        $this->info(sprintf('Processed %d pending recording(s).', $processed));

        return self::SUCCESS;
    }
}
