<?php

declare(strict_types=1);

namespace App\Compliance\Rules;

use App\Booking\Enums\BookingActor;
use App\Compliance\DTOs\SuspiciousActivitySignal;
use App\Compliance\Enums\SuspiciousActivityFlagSeverity;
use App\Compliance\Enums\SuspiciousActivityRuleCode;
use App\Compliance\Support\ComplianceThresholdSnapshot;
use App\Models\Booking;
use App\Settings\ComplianceMonitoringSettings;
use Illuminate\Support\Facades\Date;

/**
 * SRS §9.14/§24.26 — a student or instructor cancelling an unusual
 * number of bookings within a rolling window. Only Student- and
 * Instructor-attributed cancellations count; Admin/System
 * cancellations (e.g. operational cleanup, expired-reservation
 * release) are never a user-fraud signal and are skipped entirely.
 */
final class ExcessiveBookingCancellationsRule
{
    public function __construct(
        private readonly ComplianceMonitoringSettings $settings,
    ) {}

    public function evaluate(Booking $booking, BookingActor $cancelledBy): ?SuspiciousActivitySignal
    {
        if (! $this->settings->excessive_booking_cancellations_enabled) {
            return null;
        }

        $subjectId = match ($cancelledBy) {
            BookingActor::Student => $booking->student_id,
            BookingActor::Instructor => $booking->instructor_id,
            BookingActor::Admin, BookingActor::System => null,
        };

        if ($subjectId === null) {
            return null;
        }

        $column = $cancelledBy === BookingActor::Student ? 'student_id' : 'instructor_id';
        $windowDays = $this->settings->excessive_booking_cancellations_window_days;
        $cutoff = Date::now()->subDays($windowDays);

        $count = Booking::query()
            ->where($column, $subjectId)
            ->where('status', 'cancelled')
            ->where('cancelled_at', '>=', $cutoff)
            ->count();

        if ($count < $this->settings->excessive_booking_cancellations_threshold) {
            return null;
        }

        return new SuspiciousActivitySignal(
            ruleCode: SuspiciousActivityRuleCode::ExcessiveBookingCancellations,
            ruleVersion: 1,
            subjectId: $subjectId,
            actorId: null,
            occurredAt: Date::now(),
            severity: SuspiciousActivityFlagSeverity::from($this->settings->excessive_booking_cancellations_severity),
            evidence: [
                'cancelled_by' => $cancelledBy->value,
                'cancellation_count' => $count,
                'window_days' => $windowDays,
                'threshold' => $this->settings->excessive_booking_cancellations_threshold,
            ],
            thresholdSnapshot: ComplianceThresholdSnapshot::capture(SuspiciousActivityRuleCode::ExcessiveBookingCancellations, $this->settings),
            cooldownMinutes: $this->settings->excessive_booking_cancellations_cooldown_minutes,
        );
    }
}
