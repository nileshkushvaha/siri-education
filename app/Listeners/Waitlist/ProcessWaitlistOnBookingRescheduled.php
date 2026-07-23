<?php

declare(strict_types=1);

namespace App\Listeners\Waitlist;

use App\Booking\Events\BookingRescheduled;
use App\Waitlist\Services\WaitlistService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Thin trigger — the booking's previous time slot is now free. All
 * processing logic lives in WaitlistService.
 */
final class ProcessWaitlistOnBookingRescheduled implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly WaitlistService $waitlist,
    ) {}

    public function handle(BookingRescheduled $event): void
    {
        $instructor = $event->booking->instructor;

        if ($instructor === null) {
            return;
        }

        $this->waitlist->processAvailabilityOpening($instructor, 'booking_rescheduled', $event->booking->id);
    }
}
