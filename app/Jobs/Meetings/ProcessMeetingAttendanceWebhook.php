<?php

declare(strict_types=1);

namespace App\Jobs\Meetings;

use App\Booking\Contracts\MeetingAttendanceIngestionServiceInterface;
use App\Models\MeetingAttendanceProviderEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Processes one stored attendance provider event off the request path.
 * Bounded retries with backoff for transient failures (the ingestion
 * service rethrows those after marking the row); ShouldBeUnique plus
 * the service's row lock prevent duplicate concurrent processing of
 * the same provider event.
 */
class ProcessMeetingAttendanceWebhook implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public array $backoff = [30, 60, 120, 300, 600];

    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $providerEventId,
    ) {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return $this->providerEventId;
    }

    public function handle(MeetingAttendanceIngestionServiceInterface $ingestion): void
    {
        $event = MeetingAttendanceProviderEvent::query()->find($this->providerEventId);

        if ($event === null) {
            return;
        }

        $ingestion->process($event);
    }
}
