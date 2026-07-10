<?php

declare(strict_types=1);

namespace App\Booking\Events;

use App\Models\Booking;
use App\Models\BookingMeeting;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An already-created meeting's join link changed (admin re-saved a
 * manual link, or a provider retry replaced the meeting). Dispatched
 * only when the join URL actually differs — an admin re-saving without
 * a real change never fires it.
 */
final class MeetingUpdated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Booking $booking,
        public readonly BookingMeeting $meeting,
    ) {}
}
