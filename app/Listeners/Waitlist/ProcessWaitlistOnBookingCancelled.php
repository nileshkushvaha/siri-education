<?php

declare(strict_types=1);

namespace App\Listeners\Waitlist;

use App\Booking\Events\BookingCancelled;
use App\Waitlist\Services\WaitlistService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Thin trigger — a cancelled booking (direct cancellation, refund-
 * triggered cancellation, or an expired-reservation release, all of
 * which already funnel through BookingService::cancel()) frees
 * capacity for this instructor. All processing logic lives in
 * WaitlistService.
 */
final class ProcessWaitlistOnBookingCancelled implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly WaitlistService $waitlist,
    ) {}

    public function handle(BookingCancelled $event): void
    {
        $instructor = $event->booking->instructor;

        if ($instructor === null) {
            return;
        }

        $this->waitlist->processAvailabilityOpening($instructor, 'booking_cancelled', $event->booking->id);
    }
}
