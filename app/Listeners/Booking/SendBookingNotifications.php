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
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Participant (student/instructor) notifications for booking lifecycle
 * events. Every participant is an authenticated platform user (Phase
 * 17U.3 — no unauthenticated guest participant concept exists). Admin
 * notifications flow separately through the Activity Log pipeline
 * (LogsActivity → ActivityCreated → NotifyAdminsOnActivity).
 */
final class SendBookingNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function handleRequested(BookingRequested $event): void
    {
        // Approval needed — auto-confirmed bookings are covered by handleConfirmed.
        if ($event->booking->status === BookingStatus::Pending) {
            $event->booking->instructor->notify(new BookingRequestedNotification($event->booking));
        }

        // A paid booking reserves its slot and waits — tell the student
        // to complete payment before the hold lapses.
        if ($event->booking->payment_status === BookingPaymentStatus::Pending) {
            $event->booking->student->notify(new BookingPendingPaymentNotification($event->booking));
        }
    }

    public function handlePaymentSucceeded(BookingPaymentSucceeded $event): void
    {
        // Student only — the instructor never receives payment details.
        $event->booking->student->notify(new BookingPaymentSucceededNotification($event->booking));
    }

    public function handleConfirmed(BookingConfirmed $event): void
    {
        $event->booking->student->notify(new BookingConfirmedNotification($event->booking));
        $event->booking->instructor->notify(new BookingConfirmedNotification($event->booking));
    }

    public function handleCancelled(BookingCancelled $event): void
    {
        // A lapsed payment reservation reads as "expired" to the student
        // (with a path back to booking again), not as a cancellation; the
        // instructor copy stays the standard cancellation notice either way.
        $event->booking->student->notify($event->context->expired
            ? new BookingExpiredNotification($event->booking)
            : new BookingCancelledNotification($event->booking));

        $event->booking->instructor->notify(new BookingCancelledNotification($event->booking));
    }

    public function handleRescheduled(BookingRescheduled $event): void
    {
        $notification = new BookingRescheduledNotification($event->booking, $event->previousStartsAt);

        $event->booking->student->notify($notification);
        $event->booking->instructor->notify($notification);
    }

    public function handleCompleted(BookingCompleted $event): void
    {
        $event->booking->student->notify(new BookingCompletedNotification($event->booking));
        $event->booking->instructor->notify(new BookingCompletedNotification($event->booking));
    }
}
