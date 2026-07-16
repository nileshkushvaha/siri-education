<?php

declare(strict_types=1);

namespace App\Booking\Events;

use App\Models\Booking;
use App\Models\BookingMeeting;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A meeting became available (booking_meetings.status → created) for a
 * confirmed booking. Dispatched from BookingMeetingService after its
 * transaction, only on a genuine transition into Created — an idempotent
 * re-create that short-circuits on an existing created meeting never
 * re-fires it. ShouldDispatchAfterCommit: kept consistent with the rest
 * of the Booking event family — createMeeting() can be reached from a
 * caller's own outer transaction (e.g. CreateMeetingOnBookingConfirmed
 * listening to the also-after-commit BookingConfirmed), and a queued
 * listener must never observe a meeting that isn't durably committed
 * yet (Phase 17U.4).
 */
final class MeetingCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Booking $booking,
        public readonly BookingMeeting $meeting,
    ) {}
}
