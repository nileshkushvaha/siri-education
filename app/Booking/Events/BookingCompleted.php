<?php

declare(strict_types=1);

namespace App\Booking\Events;

use App\Models\Booking;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Covers both Completed and NoShow outcomes — read $booking->status.
 * ShouldDispatchAfterCommit: LessonOutcomeService::finalize()/override()
 * call syncBookingOutcome() from inside their own outer DB::transaction,
 * which reaches BookingService::finish() — queued listeners must never
 * observe a booking outcome that isn't durably committed yet (Phase
 * 17U.4).
 */
final class BookingCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Booking $booking,
    ) {}
}
