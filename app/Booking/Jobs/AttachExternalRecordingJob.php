<?php

declare(strict_types=1);

namespace App\Booking\Jobs;

use App\Booking\Services\RecordingService;
use App\Models\Recording;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The manual counterpart of CaptureLessonRecordingJob: copies an
 * operator-attached object into the platform's recording storage,
 * verifies it and publishes it — the same claim/store/verify pipeline,
 * minus provider discovery. Same queue, same single try (retry is the
 * admin's decision, shown on the recording), same uniqueness hygiene.
 *
 * The operator reference is carried by the job and resolved AGAIN when
 * it runs — credentials are never queued, and an object that stopped
 * being visible between the click and the run fails cleanly on the row
 * rather than half way through a copy.
 */
final class AttachExternalRecordingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $recordingId,
        public readonly string $operatorReference,
    ) {
        $this->onConnection('recordings');
        $this->onQueue('recordings');
    }

    public function uniqueId(): string
    {
        return $this->recordingId;
    }

    public function handle(RecordingService $recordings): void
    {
        $recording = Recording::query()->find($this->recordingId);

        if ($recording === null) {
            return;
        }

        $recordings->ingestExternal($recording, $this->operatorReference);
    }
}
