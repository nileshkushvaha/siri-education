<?php

declare(strict_types=1);

namespace App\Listeners\Booking;

use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Events\BookingConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The only automatic meeting-creation trigger: BookingConfirmed fires
 * exactly once per booking, from BookingService::confirm() (paid, after
 * verified payment) and BookingService::request() (auto-confirmed
 * demo/free bookings) — never from payment initiation, checkout-opened,
 * or Option B's late-terminal-payment path. BookingMeetingService itself
 * re-checks eligibility and idempotency, so this listener is a thin,
 * safe trigger, not a decision point.
 */
final class CreateMeetingOnBookingConfirmed implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly BookingMeetingServiceInterface $meetings,
    ) {}

    public function handle(BookingConfirmed $event): void
    {
        $this->meetings->createMeeting($event->booking);
    }
}
