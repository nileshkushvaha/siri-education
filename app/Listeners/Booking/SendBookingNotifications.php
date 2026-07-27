<?php

declare(strict_types=1);

namespace App\Listeners\Booking;

use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Events\BookingCancelled;
use App\Booking\Events\BookingCompleted;
use App\Booking\Events\BookingConfirmed;
use App\Booking\Events\BookingPaymentSucceeded;
use App\Booking\Events\BookingRequested;
use App\Booking\Events\BookingRescheduled;
use App\Notifications\Booking\BookingCancelledNotification;
use App\Notifications\Booking\BookingCompletedNotification;
use App\Notifications\Booking\BookingConfirmedNotification;
use App\Notifications\Booking\BookingExpiredNotification;
use App\Notifications\Booking\BookingPaymentSucceededNotification;
use App\Notifications\Booking\BookingPendingPaymentNotification;
use App\Notifications\Booking\BookingRequestedNotification;
use App\Notifications\Booking\BookingRescheduledNotification;
use App\Services\Notifications\NotificationIdempotencyGuard;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Participant (student/instructor) notifications for booking lifecycle
 * events. Every participant is an authenticated platform user — no
 * unauthenticated guest participant concept exists. Admin
 * notifications flow separately through the Activity Log pipeline
 * (LogsActivity → ActivityCreated → NotifyAdminsOnActivity).
 *
 * Every send is claimed through the established
 * NotificationIdempotencyGuard (already used by the Reviews/Quality
 * listener family) before dispatch: a redelivered event (queue retry
 * after a crash post-side-effect, Laravel's at-least-once delivery)
 * must never double-send. Each key includes a discriminator for
 * lifecycle states that can legitimately recur for the same booking
 * (a reschedule can happen more than once) so a genuinely new
 * transition still notifies, while an exact-same-event replay does not.
 */
final class SendBookingNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly NotificationIdempotencyGuard $idempotency,
    ) {}

    public function handleRequested(BookingRequested $event): void
    {
        // Approval needed — auto-confirmed bookings are covered by handleConfirmed.
        if ($event->booking->status === BookingStatus::Pending) {
            $this->send('booking-requested', $event->booking->id, $event->booking->instructor, new BookingRequestedNotification($event->booking));
        }

        // A paid booking reserves its slot and waits — tell the student
        // to complete payment before the hold lapses.
        if ($event->booking->payment_status === BookingPaymentStatus::Pending) {
            $this->send('booking-pending-payment', $event->booking->id, $event->booking->student, new BookingPendingPaymentNotification($event->booking));
        }
    }

    public function handlePaymentSucceeded(BookingPaymentSucceeded $event): void
    {
        // Student only — the instructor never receives payment details.
        $this->send('booking-payment-succeeded', $event->booking->id, $event->booking->student, new BookingPaymentSucceededNotification($event->booking));
    }

    public function handleConfirmed(BookingConfirmed $event): void
    {
        $notification = new BookingConfirmedNotification($event->booking);

        $this->send('booking-confirmed', $event->booking->id, $event->booking->student, $notification);
        $this->send('booking-confirmed', $event->booking->id, $event->booking->instructor, $notification);
    }

    public function handleCancelled(BookingCancelled $event): void
    {
        // A lapsed payment reservation reads as "expired" to the student
        // (with a path back to booking again), not as a cancellation; the
        // instructor copy stays the standard cancellation notice either way.
        // The student copy states the frozen refund outcome
        // ($event->refundDecision) — never a fresh recalculation, and
        // never "refund initiated" for an ineligible cancellation.
        $this->send(
            'booking-cancelled',
            $event->booking->id,
            $event->booking->student,
            $event->context->expired ? new BookingExpiredNotification($event->booking) : new BookingCancelledNotification($event->booking, $event->refundDecision),
        );

        $this->send('booking-cancelled', $event->booking->id, $event->booking->instructor, new BookingCancelledNotification($event->booking));
    }

    public function handleRescheduled(BookingRescheduled $event): void
    {
        $notification = new BookingRescheduledNotification($event->booking, $event->previousStartsAt);
        // A booking may legitimately be rescheduled more than once — the
        // new starts_at discriminates distinct reschedules from a replay
        // of the same one.
        $occurrence = $event->booking->starts_at->toIso8601String();

        $this->send('booking-rescheduled', $event->booking->id.':'.$occurrence, $event->booking->student, $notification);
        $this->send('booking-rescheduled', $event->booking->id.':'.$occurrence, $event->booking->instructor, $notification);
    }

    public function handleCompleted(BookingCompleted $event): void
    {
        $notification = new BookingCompletedNotification($event->booking);

        $this->send('booking-completed', $event->booking->id, $event->booking->student, $notification);
        $this->send('booking-completed', $event->booking->id, $event->booking->instructor, $notification);
    }

    private function send(string $type, string $discriminator, object $recipient, object $notification): void
    {
        $key = sprintf('%s:%s:%d', $type, $discriminator, $recipient->id);

        $this->idempotency->once($key, $notification::class, function () use ($recipient, $notification): void {
            $recipient->notify($notification);
        });
    }
}
