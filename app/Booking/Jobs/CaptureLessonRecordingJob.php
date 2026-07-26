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
 * Queued, dispatched afterCommit (never from inside the
 * meeting-creation transaction), delayed until
 * meeting.recording_capture_delay_minutes after the booking ends.
 * Idempotent: RecordingService::capture() re-checks the row's status
 * under a row lock before doing anything, so a duplicate dispatch (or
 * the recordings:capture sweep re-processing the same row) can never
 * import twice. One recording's failure never throws past this job —
 * capture() handles and records its own failures.
 */
final class CaptureLessonRecordingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $recordingId,
    ) {}

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
