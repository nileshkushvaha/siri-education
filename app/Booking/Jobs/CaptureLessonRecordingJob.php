<?php

declare(strict_types=1);

namespace App\Booking\Jobs;

use App\Booking\Registry\MeetingProviderRegistry;
use App\Booking\Services\RecordingService;
use App\Models\Recording;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The ONLY place a class recording is transferred. Nothing about the
 * download or upload ever happens in an HTTP request, a Livewire
 * round-trip, or a controller — a webhook or a meeting creation does
 * the minimal trusted work (identify the lesson, persist the
 * discovery) and dispatches this afterCommit.
 *
 * Queue: a dedicated `recordings` connection and queue, because an
 * upload can legitimately run for many minutes and must never sit in
 * front of time-sensitive notification or payment work. That
 * connection's retry_after is configured ABOVE this job's timeout
 * (config/queue.php) — otherwise the queue would hand the same
 * recording to a second worker while the first is still uploading it.
 *
 * tries = 1 on purpose. Retry is the domain's job, not the queue's:
 * RecordingIngestionService records a retryable state on the row and
 * the bounded recordings:capture sweep picks it up. Queue-level
 * retries would multiply concurrent uploads of the same video and
 * bypass the attempt budget and retry window entirely.
 *
 * Idempotent regardless: capture() re-checks and atomically claims the
 * row, so a duplicate dispatch, a redelivered message, or the sweep
 * arriving at the same moment all resolve to one transfer.
 */
final class CaptureLessonRecordingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Long enough for a full-length lesson recording on a slow link. */
    public int $timeout = 3600;

    /** See the class docblock — retries belong to the domain, not the queue. */
    public int $tries = 1;

    public function __construct(
        public readonly string $recordingId,
    ) {
        $this->onConnection('recordings');
        $this->onQueue('recordings');
    }

    public function handle(RecordingService $recordings, MeetingProviderRegistry $registry): void
    {
        $recording = Recording::query()->find($this->recordingId);

        if ($recording === null) {
            return;
        }

        if (! $registry->has($recording->provider)) {
            return;
        }

        $recordings->capture($recording, $registry->get($recording->provider));
    }
}
