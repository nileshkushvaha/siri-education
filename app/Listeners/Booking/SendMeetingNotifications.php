<?php

declare(strict_types=1);

namespace App\Listeners\Booking;

use App\Booking\Events\MeetingCreated;
use App\Booking\Events\MeetingUpdated;
use App\Notifications\Booking\MeetingCreatedNotification;
use App\Notifications\Booking\MeetingUpdatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Participant (student/instructor) notifications for the meeting
 * lifecycle. Both events fire only on genuine transitions
 * (BookingMeetingService::dispatchTransitionEvents), so this listener
 * never needs its own duplicate-suppression. Admin-facing failure
 * notifications flow separately through the Activity Log pipeline
 * (NotificationMapper) — never from here.
 */
final class SendMeetingNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function handleCreated(MeetingCreated $event): void
    {
        $notification = new MeetingCreatedNotification($event->booking, $event->meeting);

        $event->booking->student->notify($notification);
        $event->booking->instructor->notify($notification);
    }

    public function handleUpdated(MeetingUpdated $event): void
    {
        $notification = new MeetingUpdatedNotification($event->booking, $event->meeting);

        $event->booking->student->notify($notification);
        $event->booking->instructor->notify($notification);
    }
}
