<?php

declare(strict_types=1);

namespace App\Listeners\Package;

use App\Booking\Events\BookingCancelled;
use App\Package\Services\PackageEntitlementService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Phase 4D — returns a cancelled package-funded booking's committed
 * unit to the student's available balance (spec §22).
 *
 * Hangs off the canonical BookingCancelled event rather than any one
 * controller or Livewire component, so EVERY cancellation path releases
 * capacity the same way: student-initiated, instructor-initiated,
 * admin, and the system's own `booking:release-expired` sweep all
 * dispatch this single event through BookingService::cancel().
 *
 * What it deliberately does NOT do is touch `used_quantity` or write a
 * consumption row. A cancelled lesson was never delivered, so nothing
 * was consumed — the reservation simply moves Reserved → Released and
 * the unit becomes bookable again. That is the whole difference between
 * this listener and ConsumePackageEntitlementOnLessonCompleted, and it
 * is why the two can never both act on the same booking: releasing
 * requires a still-Reserved reservation, and consumption claims it
 * inside the same transaction that writes the ledger row.
 *
 * releaseForBooking() is idempotent and never throws for a booking with
 * no reservation, an already-released one, or one already consumed — a
 * retried delivery of this queued listener is safe, and a cancellation
 * must never fail because of package bookkeeping.
 */
final class ReleasePackageReservationOnBookingCancelled implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly PackageEntitlementService $entitlements,
    ) {}

    public function handle(BookingCancelled $event): void
    {
        if ($event->booking->package_entitlement_id === null) {
            return;
        }

        $this->entitlements->releaseForBooking($event->booking, 'booking_cancelled');
    }
}
