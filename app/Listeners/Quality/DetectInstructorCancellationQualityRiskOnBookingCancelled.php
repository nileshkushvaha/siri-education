<?php

declare(strict_types=1);

namespace App\Listeners\Quality;

use App\Booking\Events\BookingCancelled;
use App\Quality\Actions\DetectInstructorCancellationQualityRiskAction;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Thin trigger — all detection logic lives in DetectInstructorCancellationQualityRiskAction. */
final class DetectInstructorCancellationQualityRiskOnBookingCancelled implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly DetectInstructorCancellationQualityRiskAction $detect,
    ) {}

    public function handle(BookingCancelled $event): void
    {
        $this->detect->execute($event->booking, $event->context);
    }
}
