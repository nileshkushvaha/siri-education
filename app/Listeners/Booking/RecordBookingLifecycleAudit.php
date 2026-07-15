<?php

declare(strict_types=1);

namespace App\Listeners\Booking;

use App\Booking\Events\BookingCancelled;
use App\Booking\Events\BookingCompleted;
use App\Booking\Events\BookingConfirmed;
use App\Booking\Events\BookingRequested;
use App\Booking\Events\BookingRescheduled;
use App\Models\Booking;
use App\Services\AuditTrailService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Writes semantic booking lifecycle entries ('bookings' log) via
 * AuditTrailService. NotificationMapper maps these events to admin
 * notifications (ActivityCreated → NotifyAdminsOnActivity), keeping
 * the decisions-doc rule intact: notifications originate from the
 * Activity Log pipeline, never from Services.
 */
final class RecordBookingLifecycleAudit implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    public function handleRequested(BookingRequested $event): void
    {
        $this->log($event->booking, 'booking_requested', sprintf(
            'Booking %s requested — %s with %s on %s by %s',
            $event->booking->reference,
            $event->booking->type->name,
            $event->booking->instructor->name,
            $this->when($event->booking),
            $event->booking->student->name,
        ));
    }

    public function handleConfirmed(BookingConfirmed $event): void
    {
        $this->log($event->booking, 'booking_confirmed', sprintf(
            'Booking %s confirmed — %s with %s on %s',
            $event->booking->reference,
            $event->booking->type->name,
            $event->booking->instructor->name,
            $this->when($event->booking),
        ));
    }

    public function handleCancelled(BookingCancelled $event): void
    {
        $this->log($event->booking, 'booking_cancelled', sprintf(
            'Booking %s cancelled by %s%s',
            $event->booking->reference,
            $event->context->cancelledBy->label(),
            $event->context->reason !== null ? ' — '.$event->context->reason : '',
        ));
    }

    public function handleRescheduled(BookingRescheduled $event): void
    {
        $this->log($event->booking, 'booking_rescheduled', sprintf(
            'Booking %s rescheduled from %s to %s',
            $event->booking->reference,
            $event->previousStartsAt->timezone($event->booking->timezone)->format('D, M j Y H:i'),
            $this->when($event->booking),
        ));
    }

    public function handleCompleted(BookingCompleted $event): void
    {
        $this->log($event->booking, 'booking_completed', sprintf(
            'Booking %s finished with status "%s"',
            $event->booking->reference,
            $event->booking->status->label(),
        ));
    }

    private function log(Booking $booking, string $event, string $description): void
    {
        // Queued context has no authenticated user; the precise actor is
        // already recorded in booking_activities. System actor is correct here.
        $this->audit->logSystem('bookings', $event, $description, $booking);
    }

    private function when(Booking $booking): string
    {
        return $booking->starts_at->timezone($booking->timezone)
            ->format('D, M j Y H:i').' ('.$booking->timezone.')';
    }
}
