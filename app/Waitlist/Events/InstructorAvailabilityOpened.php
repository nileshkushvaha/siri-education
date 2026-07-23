<?php

declare(strict_types=1);

namespace App\Waitlist\Events;

use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * SRS §10.28/§10.33-4: fired whenever an instructor gains new bookable
 * capacity through a path this phase evidences as a real trigger —
 * a new/reactivated availability window, or vacation mode ending.
 * (Booking cancellation/reschedule reuse the existing BookingCancelled/
 * BookingRescheduled events instead of this one.)
 *
 * ShouldDispatchAfterCommit — mirrors every other Booking/Wallet/
 * LearningPlan domain event: a queued listener must never process a
 * waitlist against an availability change that isn't durably
 * committed yet.
 *
 * $reason/$triggerId together form the notification idempotency key
 * (WaitlistService::processAvailabilityOpening()) — never reused
 * across genuinely distinct opening events, so a real second opening
 * legitimately re-notifies a still-Waiting entry.
 */
final class InstructorAvailabilityOpened implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly User $instructor,
        public readonly string $reason,
        public readonly string $triggerId,
    ) {}
}
