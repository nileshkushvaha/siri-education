<?php

declare(strict_types=1);

namespace App\Booking\Events;

use App\Models\Booking;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ShouldDispatchAfterCommit: BookingPaymentService::markPaid() calls
 * BookingService::confirm() from inside its own outer DB::transaction —
 * queued listeners (meeting creation, lesson creation, notifications)
 * must never observe a confirmation that isn't durably committed yet.
 */
final class BookingConfirmed implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Booking $booking,
    ) {}
}
