<?php

declare(strict_types=1);

namespace App\Listeners\Waitlist;

use App\Booking\Events\BookingConfirmed;
use App\Waitlist\Services\WaitlistService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Thin trigger — closes out the confirming student's own waitlist
 * entry for this instructor, if one exists. BookingConfirmed only
 * fires once a booking is authoritatively confirmed (never for a
 * still-pending or failed attempt), so a failed booking can never
 * falsely fulfil a waitlist entry. All processing logic lives in
 * WaitlistService.
 */
final class FulfillWaitlistOnBookingConfirmed implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly WaitlistService $waitlist,
    ) {}

    public function handle(BookingConfirmed $event): void
    {
        $this->waitlist->fulfillForBooking($event->booking);
    }
}
