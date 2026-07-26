<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\DTOs\RescheduleAllowance;
use App\Booking\Enums\BookingActivityAction;
use App\Booking\Enums\BookingActor;
use App\Models\Booking;
use App\Models\BookingActivity;
use App\Settings\BookingSettings;

/**
 * SRS §11.26: at most BookingSettings::reschedule_limit successful
 * student-initiated reschedules per booking (or per recurring
 * occurrence, since each occurrence is its own Booking row). Instructor/
 * admin/system reschedules are not governed by this limit, mirroring
 * CancellationRefundPolicy's actor split for the same reason: there is
 * no SRS-defined student allowance for a change the student didn't
 * initiate.
 *
 * The successful count is read from the append-only booking_activities
 * timeline (BookingActivityAction::Rescheduled rows), written in the
 * same transaction as the booking update — never from updated_at, and
 * never from a separately maintained counter that could drift.
 */
final class RescheduleLimitPolicy
{
    public function __construct(
        private readonly BookingSettings $settings,
    ) {}

    public function decide(Booking $booking, BookingActor $actor): RescheduleAllowance
    {
        $limit = max(0, $this->settings->reschedule_limit);

        // Scoped to actor_type = Student: a "genuine recovery/correction"
        // reschedule by an instructor/admin/system must not silently
        // consume the student's own allowance (Step 2 of the SRS
        // interpretation) — the count always represents successful
        // *student-initiated* reschedules of this booking, never the
        // total regardless of who acted.
        $priorSuccessfulCount = BookingActivity::query()
            ->where('booking_id', $booking->id)
            ->forAction(BookingActivityAction::Rescheduled)
            ->where('actor_type', BookingActor::Student)
            ->count();

        if ($actor !== BookingActor::Student) {
            return new RescheduleAllowance(
                allowed: true,
                policyCode: 'not_student_governed',
                limit: $limit,
                priorSuccessfulCount: $priorSuccessfulCount,
                overrideApplies: true,
            );
        }

        $allowed = $priorSuccessfulCount < $limit;

        return new RescheduleAllowance(
            allowed: $allowed,
            policyCode: $allowed ? 'allowed' : 'limit_reached',
            limit: $limit,
            priorSuccessfulCount: $priorSuccessfulCount,
            overrideApplies: false,
        );
    }
}
