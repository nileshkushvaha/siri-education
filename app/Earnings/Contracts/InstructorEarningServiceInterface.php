<?php

declare(strict_types=1);

namespace App\Earnings\Contracts;

use App\Earnings\Exceptions\CompensationBlockedException;
use App\Earnings\Exceptions\EarningException;
use App\Models\DemoConversionIncentiveAward;
use App\Models\InstructorEarning;
use App\Models\InstructorSettlementBatch;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Single entry point for every earning/settlement mutation. Listeners,
 * commands, and Filament actions all call this — no scattered writes.
 * Instructor earnings are NOT the student price; calculation happens
 * here from the booking's paid snapshot and platform rules.
 */
interface InstructorEarningServiceInterface
{
    /**
     * Create the earning for an eligible completed lesson. Idempotent
     * (returns the existing earning; restores a disputed-hold earning
     * when the dispute was resolved by re-completion) and
     * eligibility-guarded (returns null and audits when the lesson or
     * its amounts do not qualify).
     */
    public function createFromLesson(Lesson $lesson): ?InstructorEarning;

    /**
     * Create an admin-approved reconciliation earning for a lesson that
     * never completed (approved student no-show / both-absent
     * compensation, or an override to Completed whose earning is
     * missing). Same compensation resolver, creation action, and
     * snapshot as createFromLesson — only the completed-status
     * requirement is replaced by the explicit approval. Idempotent
     * (returns the existing earning); returns null when compensation
     * legitimately does not apply (earnings disabled, demo policy none,
     * periodic basis).
     *
     * @throws EarningException when the booking is missing/cancelled/unpaid
     * @throws CompensationBlockedException when no agreement covers the lesson
     */
    public function createForReconciliation(Lesson $lesson, ?User $actor = null, ?string $reasonCode = null): ?InstructorEarning;

    /**
     * Create the earning for an already-awarded demo-to-paid conversion
     * incentive. The award itself (idempotency, eligibility,
     * concurrency) is DemoConversionIncentiveService's responsibility —
     * this only writes the earning, exactly once per award, reusing
     * the same settings-gated (earnings_enabled) and hold_days
     * conventions as createFromLesson(). Idempotent: returns the
     * existing earning if this award already has one.
     */
    public function createDemoConversionIncentive(DemoConversionIncentiveAward $award, Lesson $paidLesson, Lesson $demoLesson): ?InstructorEarning;

    /**
     * Promote pending_hold → releasable. Without $override the hold
     * period must have lapsed; admin actions pass override.
     *
     * @throws EarningException
     */
    public function release(InstructorEarning $earning, ?User $actor = null, bool $override = false): InstructorEarning;

    /** @throws EarningException */
    public function reverse(InstructorEarning $earning, ?User $actor = null, ?string $reason = null): InstructorEarning;

    /** Park a non-settled earning while its lesson is disputed. */
    public function holdForDispute(InstructorEarning $earning): InstructorEarning;

    /**
     * Restore a disputed-hold earning to pending_hold after the dispute
     * resolved in the instructor's favour (the existing restore
     * transition, exposed for earning reconciliation). Idempotent.
     *
     * @throws EarningException
     */
    public function holdRestore(InstructorEarning $earning, ?User $actor = null): InstructorEarning;

    /** Release sweep: promote every due pending_hold earning. Returns the count. */
    public function releaseDue(): int;

    /**
     * Draft a settlement batch from this instructor's releasable,
     * unassigned earnings in one currency (optionally bounded by
     * released_at range).
     *
     * @throws EarningException
     */
    public function createSettlementBatch(
        int $instructorId,
        string $currencyCode,
        ?CarbonInterface $periodStart = null,
        ?CarbonInterface $periodEnd = null,
        ?User $actor = null,
        ?string $notes = null,
    ): InstructorSettlementBatch;

    /** @throws EarningException */
    public function approveSettlementBatch(InstructorSettlementBatch $batch, User $actor): InstructorSettlementBatch;

    /**
     * Manual admin confirmation that money moved outside the system —
     * batch → paid, its earnings → settled. No external transfer runs.
     *
     * @throws EarningException
     */
    public function markSettlementBatchPaid(InstructorSettlementBatch $batch, User $actor, ?string $paymentReference = null): InstructorSettlementBatch;

    /**
     * Cancel a draft/failed batch: earnings are detached and remain
     * releasable for a future batch.
     *
     * @throws EarningException
     */
    public function cancelSettlementBatch(InstructorSettlementBatch $batch, User $actor, ?string $reason = null): InstructorSettlementBatch;
}
