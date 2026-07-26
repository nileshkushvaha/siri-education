<?php

declare(strict_types=1);

namespace App\Booking\Events;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ShouldDispatchAfterCommit: kept consistent with the rest of the
 * Booking event family — reschedule() can be reached from a caller's
 * own outer transaction, and queued listeners must never observe a
 * reschedule that isn't durably committed yet.
 */
final class BookingRescheduled implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Booking $booking,
        public readonly CarbonImmutable $previousStartsAt,
        public readonly CarbonImmutable $previousEndsAt,
    ) {}
}
