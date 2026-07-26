<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

/**
 * The outcome of RescheduleLimitPolicy::decide(), computed fresh from
 * the durable booking_activities timeline every time (never
 * cached/frozen the way CancellationRefundDecision is), since a
 * reschedule request must always be judged against the current
 * configured limit and the current successful count at the moment it
 * is attempted.
 */
final readonly class RescheduleAllowance
{
    public function __construct(
        public bool $allowed,
        /** e.g. 'allowed', 'limit_reached', 'not_student_governed' */
        public string $policyCode,
        public int $limit,
        /** Successful reschedules already recorded before this attempt. */
        public int $priorSuccessfulCount,
        /** True for actors the configured limit does not govern (non-student). */
        public bool $overrideApplies,
    ) {}

    public function remaining(): int
    {
        return max(0, $this->limit - $this->priorSuccessfulCount);
    }

    /** @return array<string, mixed> safe, identifier-free metadata for audit/activity logs */
    public function toMeta(): array
    {
        return [
            'reschedule_limit_applied' => $this->limit,
            'reschedule_successful_ordinal' => $this->priorSuccessfulCount + 1,
            'reschedule_override_applied' => $this->overrideApplies,
            'reschedule_policy_code' => $this->policyCode,
        ];
    }
}
