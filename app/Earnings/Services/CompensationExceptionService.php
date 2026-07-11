<?php

declare(strict_types=1);

namespace App\Earnings\Services;

use App\Earnings\Enums\CompensationExceptionCategory;
use App\Models\InstructorCompensationException;
use App\Models\InstructorEarning;
use App\Models\Lesson;
use App\Services\AuditTrailService;
use App\Settings\InstructorEarningSettings;

/**
 * Owns the compensation-exception queue: one open row per blocked
 * lesson, updated on every attempt (attempt_count / last_attempt_at /
 * current category), resolved — never deleted — once the earning
 * exists. Reasons stored here are UI-safe; the audit trail gets the
 * category event (missing_agreement keeps its Phase 14.2 event name so
 * the admin NotificationMapper alert continues to fire).
 */
final class CompensationExceptionService
{
    private const string LOG_NAME = 'instructor_compensation';

    public function __construct(
        private readonly AuditTrailService $audit,
        private readonly InstructorEarningSettings $settings,
    ) {}

    /**
     * Bounded retry backoff (Phase 14.5): after attempt N, the next
     * automatic sweep pick-up is delayed by an escalating schedule —
     * attempt 1 → next hourly sweep, 2 → +2h, 3 → +6h, 4 → +24h, then
     * daily — until compensation_retry_max_attempts marks the exception
     * exhausted. Exhausted and permanent exceptions never loop; the
     * permission-protected manual retry remains available regardless.
     */
    private const array RETRY_DELAY_HOURS = [1 => 0, 2 => 2, 3 => 6, 4 => 24];

    public function record(Lesson $lesson, CompensationExceptionCategory $category, string $safeReason): InstructorCompensationException
    {
        $exception = InstructorCompensationException::query()->firstOrNew(['lesson_id' => $lesson->id]);

        $attempts = ($exception->attempt_count ?? 0) + 1;
        $maxAttempts = max(1, $this->settings->compensation_retry_max_attempts);
        $exhausted = $attempts >= $maxAttempts;

        $exception->fill([
            'booking_id' => $lesson->booking_id,
            'instructor_id' => $lesson->instructor_id,
            'scheduled_start_at' => $lesson->starts_at ?? now(),
            'category' => $category,
            'reason' => $safeReason,
            'retry_eligible' => $category->isRetryEligible(),
            'attempt_count' => $attempts,
            'first_failed_at' => $exception->first_failed_at ?? now(),
            'last_attempt_at' => now(),
            'next_retry_at' => $category->isRetryEligible() && ! $exhausted
                ? now()->addHours(self::RETRY_DELAY_HOURS[$attempts] ?? 24)
                : null,
            'retry_exhausted_at' => $exhausted && $category->isRetryEligible()
                ? ($exception->retry_exhausted_at ?? now())
                : null,
            'resolved_at' => null,
            'resolved_earning_id' => null,
        ])->save();

        $this->audit->logSystem(self::LOG_NAME, $category->auditEvent(), sprintf('Earning blocked for lesson %s (%s): %s', $lesson->id, $category->value, $safeReason), $lesson, [
            'category' => $category->value,
            'attempt_count' => $exception->attempt_count,
        ]);

        return $exception;
    }

    /** The earning finally exists — close the queue row, keep the history. */
    public function resolve(Lesson $lesson, InstructorEarning $earning): void
    {
        $exception = InstructorCompensationException::query()->open()->where('lesson_id', $lesson->id)->first();

        if ($exception === null) {
            return;
        }

        $exception->fill([
            'resolved_at' => now(),
            'resolved_earning_id' => $earning->id,
        ])->save();

        $this->audit->logSystem(self::LOG_NAME, 'compensation_exception_resolved', sprintf('Compensation exception for lesson %s resolved by earning %s.', $lesson->id, $earning->id), $exception);
    }

    /** The lesson can never earn (e.g. its booking left the eligible state) — stop retrying, keep it visible. */
    public function markPermanentlyIneligible(Lesson $lesson, string $safeReason): void
    {
        $exception = InstructorCompensationException::query()->open()->where('lesson_id', $lesson->id)->first();

        if ($exception === null) {
            return;
        }

        $exception->fill([
            'category' => CompensationExceptionCategory::PermanentlyIneligible,
            'reason' => $safeReason,
            'retry_eligible' => false,
            'last_attempt_at' => now(),
        ])->save();

        $this->audit->logSystem(self::LOG_NAME, CompensationExceptionCategory::PermanentlyIneligible->auditEvent(), sprintf('Compensation exception for lesson %s is permanently ineligible: %s', $lesson->id, $safeReason), $exception);
    }
}
