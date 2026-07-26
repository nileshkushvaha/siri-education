<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\DTOs\CancellationRefundDecision;
use App\Booking\Enums\BookingActor;
use App\Models\Booking;
use App\Settings\BookingSettings;
use Carbon\CarbonImmutable;

/**
 * SRS 11.24 ("Refund amount depends on cancellation window and
 * policy") / SRS 6.8 ("Cancellation windows are configurable"). The
 * single place a cancellation's refund eligibility is decided,
 * reused from both the UI preview and the authoritative execution path
 * (BookingService::cancel()) so neither can drift from the other.
 *
 * Only a Student-initiated cancellation is ever subject to the window:
 * instructor/admin/system-initiated cancellations always refund in
 * full, matching the SRS's general responsibility principle (the
 * student is never penalized for a cancellation they did not cause —
 * see e.g. 11.28's unconditional instructor-no-show refund). Technical
 * issues never reach this policy at all — they are resolved through
 * the separate lesson-outcome/dispute pipeline, not BookingService::cancel().
 *
 * Pure and deterministic: every input (booking, actor, cancelledAt) is
 * supplied explicitly, never read from now() or the currently
 * authenticated user, so the same call always produces the same
 * answer regardless of when or from where it runs.
 */
final class CancellationRefundPolicy
{
    public function __construct(
        private readonly BookingSettings $settings,
    ) {}

    public function decide(Booking $booking, BookingActor $actor, CarbonImmutable $cancelledAt): CancellationRefundDecision
    {
        $startsAt = CarbonImmutable::instance($booking->starts_at);
        $windowHours = max(0, $this->settings->cancellation_window_hours);

        if ($actor !== BookingActor::Student) {
            return new CancellationRefundDecision(
                eligible: true,
                policyCode: 'not_student_initiated',
                cutoffAt: null,
                windowHours: $windowHours,
                cancelledAt: $cancelledAt,
                startsAt: $startsAt,
            );
        }

        $cutoffAt = $startsAt->subHours($windowHours);
        $eligible = $cancelledAt->lessThanOrEqualTo($cutoffAt);

        return new CancellationRefundDecision(
            eligible: $eligible,
            policyCode: $eligible ? 'before_cutoff' : 'late_cancellation',
            cutoffAt: $cutoffAt,
            windowHours: $windowHours,
            cancelledAt: $cancelledAt,
            startsAt: $startsAt,
        );
    }
}
