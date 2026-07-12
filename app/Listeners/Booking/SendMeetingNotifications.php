<?php

declare(strict_types=1);

namespace App\Listeners\Booking;

use App\Booking\Events\MeetingCreated;
use App\Booking\Events\MeetingUpdated;
use App\Models\Booking;
use App\Models\User;
use App\Notifications\Booking\MeetingCreatedNotification;
use App\Notifications\Booking\MeetingUpdatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Participant (attendee/host) notifications for the meeting lifecycle.
 * Both events fire only on genuine transitions (BookingMeetingService::
 * dispatchTransitionEvents), so this listener never needs its own
 * duplicate-suppression. Admin-facing failure notifications flow
 * separately through the Activity Log pipeline (NotificationMapper) —
 * never from here.
 */
final class SendMeetingNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function handleCreated(MeetingCreated $event): void
    {
        $notification = new MeetingCreatedNotification($event->booking, $event->meeting);

        $this->notifyAttendee($event->booking, $notification);
        // Null-safe: eligibility required a host at creation time, but this
        // listener runs later on the queue — a since-removed host must not
        // burn the job's retries and take the attendee's email with it.
        $event->booking->host?->notify($notification);
    }

    public function handleUpdated(MeetingUpdated $event): void
    {
        $notification = new MeetingUpdatedNotification($event->booking, $event->meeting);

        $this->notifyAttendee($event->booking, $notification);
        $event->booking->host?->notify($notification);
    }

    private function notifyAttendee(Booking $booking, Notification $notification): void
    {
        $this->attendeeNotifiable($booking)?->notify($notification);
    }

    private function attendeeNotifiable(Booking $booking): AnonymousNotifiable|User|null
    {
        if ($booking->attendee !== null) {
            return $booking->attendee;
        }

        return $booking->guest_email !== null
            ? NotificationFacade::route('mail', $booking->guest_email)
            : null;
    }
}
