<?php

declare(strict_types=1);

namespace App\Booking\Events;

use App\Models\Booking;
use App\Models\BookingMeeting;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A meeting became available (booking_meetings.status → created) for a
 * confirmed booking. Dispatched from BookingMeetingService after its
 * transaction, only on a genuine transition into Created — an idempotent
 * re-create that short-circuits on an existing created meeting never
 * re-fires it.
 */
final class MeetingCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Booking $booking,
        public readonly BookingMeeting $meeting,
    ) {}
}
