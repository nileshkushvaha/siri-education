<?php

declare(strict_types=1);

namespace App\Quality\Support;

use App\Booking\DTOs\CancelBookingData;
use App\Booking\Enums\BookingActor;

/**
 * Whether a cancellation is attributable to the instructor for
 * quality-signal purposes. `BookingActor::Host` is the instructor
 * side of a booking (see `Booking::host_id`) — every other actor
 * (Attendee/System/Admin) is structurally excluded by construction,
 * which is exactly the exclusion list the spec asks for: student
 * cancellations (Attendee), system expiration and payment-failure
 * cancellations (System), and administrator corrections (Admin) never
 * satisfy `Host`, so no reason-string parsing is needed or attempted.
 */
final class InstructorCancellationAttribution
{
    public static function isInstructorAttributable(CancelBookingData $context): bool
    {
        return $context->cancelledBy === BookingActor::Host;
    }
}
