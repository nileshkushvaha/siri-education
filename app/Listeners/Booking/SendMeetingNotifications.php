<?php

declare(strict_types=1);

namespace App\Listeners\Booking;

use App\Booking\Events\MeetingCreated;
use App\Booking\Events\MeetingUpdated;
use App\Notifications\Booking\MeetingCreatedNotification;
use App\Notifications\Booking\MeetingUpdatedNotification;
use App\Services\Notifications\NotificationIdempotencyGuard;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Participant (student/instructor) notifications for the meeting
 * lifecycle. Both events fire only on genuine transitions
 * (BookingMeetingService::dispatchTransitionEvents), so no in-process
 * duplicate-trigger exists — but the queue's at-least-once delivery
 * can still redeliver the SAME event instance (job retry after a crash
 * post-side-effect), so each send is still claimed through the
 * established NotificationIdempotencyGuard (Phase 17V closure), same
 * as SendBookingNotifications. MeetingUpdated can legitimately recur
 * for the same meeting (join URL changes again), so the join URL's
 * hash discriminates a genuinely new update from a replay of the same
 * one. Admin-facing failure notifications flow separately through the
 * Activity Log pipeline (NotificationMapper) — never from here.
 */
final class SendMeetingNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly NotificationIdempotencyGuard $idempotency,
    ) {}

    public function handleCreated(MeetingCreated $event): void
    {
        $notification = new MeetingCreatedNotification($event->booking, $event->meeting);

        $this->send('meeting-created', $event->meeting->id, $event->booking->student, $notification);
        $this->send('meeting-created', $event->meeting->id, $event->booking->instructor, $notification);
    }

    public function handleUpdated(MeetingUpdated $event): void
    {
        $notification = new MeetingUpdatedNotification($event->booking, $event->meeting);
        $occurrence = $event->meeting->id.':'.hash('sha256', (string) $event->meeting->join_url);

        $this->send('meeting-updated', $occurrence, $event->booking->student, $notification);
        $this->send('meeting-updated', $occurrence, $event->booking->instructor, $notification);
    }

    private function send(string $type, string $discriminator, object $recipient, object $notification): void
    {
        $key = sprintf('%s:%s:%d', $type, $discriminator, $recipient->id);

        $this->idempotency->once($key, $notification::class, function () use ($recipient, $notification): void {
            $recipient->notify($notification);
        });
    }
}
