<?php

declare(strict_types=1);

namespace App\Listeners\Booking;

use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Events\BookingCancelled;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A booking cancelled after confirmation takes its meeting with it —
 * the provider-side resource (Google Calendar event / Meet link, Zoom
 * meeting) must not stay live and joinable once the booking no longer
 * exists. Tolerant: no meeting row, or one already cancelled, is a
 * no-op inside BookingMeetingService::cancelMeeting(). A provider-side
 * cancellation failure is recorded there as meeting_cancellation_failed
 * and raises an admin notification — it is never retried blindly here.
 */
final class CancelMeetingOnBookingCancelled implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly BookingMeetingServiceInterface $meetings,
    ) {}

    public function handle(BookingCancelled $event): void
    {
        $this->meetings->cancelMeeting($event->booking);
    }
}
