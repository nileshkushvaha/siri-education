<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use Carbon\CarbonImmutable;

/**
 * Phase 24C — the frozen outcome of CancellationRefundPolicy::decide(),
 * computed once at cancellation time and threaded through
 * BookingCancelled so every downstream consumer (async wallet-refund
 * execution, notifications, dashboards) reads the SAME answer instead
 * of recomputing eligibility against a setting/clock that may have
 * since changed. Carries no payment amount/currency — those come from
 * the immutable BookingPayment row at execution time (see
 * BookingPaymentService::refundToWallet()), never from this DTO or the
 * booking-type's current price.
 */
final readonly class CancellationRefundDecision
{
    public function __construct(
        public bool $eligible,
        /** e.g. 'before_cutoff', 'late_cancellation', 'not_student_initiated' */
        public string $policyCode,
        /** Null only for policyCode = 'not_student_initiated', where the window never applies. */
        public ?CarbonImmutable $cutoffAt,
        public int $windowHours,
        public CarbonImmutable $cancelledAt,
        public CarbonImmutable $startsAt,
    ) {}

    /** @return array<string, mixed> safe, identifier-free metadata for audit/activity logs */
    public function toMeta(): array
    {
        return [
            'refund_eligible' => $this->eligible,
            'refund_policy_code' => $this->policyCode,
            'refund_cutoff_at' => $this->cutoffAt?->toIso8601String(),
            'refund_window_hours' => $this->windowHours,
            'refund_decided_at' => $this->cancelledAt->toIso8601String(),
            'refund_starts_at' => $this->startsAt->toIso8601String(),
        ];
    }
}
