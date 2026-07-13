<?php

declare(strict_types=1);

namespace App\Earnings\Actions;

use App\Earnings\Contracts\InstructorEarningRepositoryInterface;
use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\DTOs\LessonEarningReconciliationResult;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Enums\LessonFinancialDispositionStatus;
use App\Earnings\Events\LessonEarningReconciled;
use App\Earnings\Exceptions\CompensationBlockedException;
use App\Earnings\Exceptions\EarningException;
use App\Lessons\Enums\LessonOutcome;
use App\Models\InstructorEarning;
use App\Models\Lesson;
use App\Models\LessonFinancialDisposition;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\DB;

/**
 * The single executor of approved instructor-earning reconciliations
 * (Phase 17G). Every money-adjacent step goes through the existing
 * earning mechanisms — createForReconciliation (agreement-resolved,
 * snapshot-preserving), holdForDispute, release, reverse — never a
 * second calculator or settlement path. Instructor pay is never
 * derived from student price or payment records (the cross-domain
 * isolation guard enforces this at the source level).
 *
 * Eligibility problems are committed DECISIONS (manual review with a
 * reason); infrastructure failures THROW and roll back, so a
 * disposition can never be Resolved without its reconciliation having
 * actually happened. Settled money is never mutated — those conflicts
 * park as manual recovery.
 */
final class ExecuteLessonEarningReconciliationAction
{
    private const string LOG_NAME = 'instructor_earnings';

    /** Reason codes a disposition may carry to qualify for reconciliation. */
    public const array RECONCILIATION_REASONS = [
        'earning_reconciliation_required',
        'student_no_show_earning_reconciliation_required',
    ];

    public function __construct(
        private readonly InstructorEarningServiceInterface $earnings,
        private readonly InstructorEarningRepositoryInterface $earningRepository,
        private readonly AuditTrailService $audit,
    ) {}

    /** @throws EarningException */
    public function execute(LessonFinancialDisposition $disposition, ?User $admin = null): LessonEarningReconciliationResult
    {
        return DB::transaction(function () use ($disposition, $admin): LessonEarningReconciliationResult {
            $disposition = LessonFinancialDisposition::query()
                ->whereKey($disposition->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Revalidate under the lock: only approved, un-held rows with
            // a reconciliation reason execute — never unresolved reviews.
            if ($disposition->processing_status !== LessonFinancialDispositionStatus::Ready
                || $disposition->admin_hold
                || ! in_array($disposition->reason_code, self::RECONCILIATION_REASONS, strict: true)) {
                throw new EarningException('This disposition is not approved and ready for earning reconciliation.');
            }

            $lesson = $disposition->lesson?->refresh();

            if ($lesson === null) {
                return $this->defer($disposition, $admin, 'missing_lesson');
            }

            $earning = $this->earningRepository->findForLesson($lesson);

            return $earning !== null
                ? $this->reconcileExisting($disposition, $lesson, $earning, $admin)
                : $this->reconcileMissing($disposition, $lesson, $admin);
        });
    }

    // ── Existing earning ──────────────────────────────────────────────────

    private function reconcileExisting(LessonFinancialDisposition $disposition, Lesson $lesson, InstructorEarning $earning, ?User $admin): LessonEarningReconciliationResult
    {
        $previousStatus = $earning->status;

        // Already written off — an idempotent no-op, never re-reversed.
        if ($earning->status === InstructorEarningStatus::Reversed) {
            return $this->resolve($disposition, $lesson, $earning, $admin, 'already_reversed', $previousStatus, applied: false);
        }

        // Settled/paid money is never mutated — manual recovery only.
        if ($earning->status->isTerminal()) {
            return $this->defer($disposition, $admin, 'settled_earning_manual_recovery');
        }

        [$action, $earning] = match (true) {
            // Outcome stands (or was corrected back) in the instructor's
            // favour: a parked earning is restored and released through
            // the existing mechanisms; a healthy one needs nothing.
            $lesson->outcome === LessonOutcome::Completed => $earning->status === InstructorEarningStatus::DisputedHold
                ? ['restored_released', $this->restoreAndRelease($earning, $admin)]
                : ['earning_intact', $earning],

            // Technical issue: park the earning until a human decides.
            $lesson->outcome === LessonOutcome::TechnicalIssue => $earning->status === InstructorEarningStatus::DisputedHold
                ? ['held', $earning]
                : ['held', $this->earnings->holdForDispute($earning)],

            // Corrected against the instructor: reverse the unsettled
            // earning (detaching from any open batch first, via the
            // existing dispute-hold mechanism).
            default => ['reversed', $this->reverseSafely($earning, $admin, $disposition)],
        };

        return $this->resolve($disposition, $lesson, $earning, $admin, $action, $previousStatus, applied: true);
    }

    // ── Missing earning ───────────────────────────────────────────────────

    private function reconcileMissing(LessonFinancialDisposition $disposition, Lesson $lesson, ?User $admin): LessonEarningReconciliationResult
    {
        // Never compensable — an approved row here is a contradiction.
        if (in_array($lesson->outcome, [LessonOutcome::InstructorNoShow, LessonOutcome::Cancelled, LessonOutcome::Pending], strict: true)) {
            return $this->defer($disposition, $admin, 'outcome_not_compensable');
        }

        try {
            $earning = $this->earnings->createForReconciliation($lesson, $admin, $disposition->reason_code);
        } catch (CompensationBlockedException|EarningException $e) {
            // Unpaid booking, cancelled booking, or no covering agreement —
            // explicit approval cannot conjure eligibility; a human decides.
            return $this->defer($disposition, $admin, 'compensation_unresolvable', $e->getMessage());
        }

        if ($earning === null) {
            // Benign non-compensation (demo policy none / periodic basis).
            return $this->resolve($disposition, $lesson, null, $admin, 'no_earning_required', null, applied: false);
        }

        return $this->resolve($disposition, $lesson, $earning, $admin, 'created', null, applied: true);
    }

    // ── Mechanics ─────────────────────────────────────────────────────────

    /** DisputedHold → PendingHold (the existing restore transition) → Releasable. */
    private function restoreAndRelease(InstructorEarning $earning, ?User $admin): InstructorEarning
    {
        $earning = $this->earnings->holdRestore($earning, $admin);

        return $this->earnings->release($earning, $admin, override: true);
    }

    /** Reversal must never ride inside an open settlement batch — detach via the existing hold first. */
    private function reverseSafely(InstructorEarning $earning, ?User $admin, LessonFinancialDisposition $disposition): InstructorEarning
    {
        if ($earning->settlement_batch_id !== null) {
            $earning = $this->earnings->holdForDispute($earning);
        }

        return $this->earnings->reverse($earning, $admin, sprintf(
            'Outcome corrected to %s (disposition %s).',
            $disposition->lesson?->outcome->value ?? 'unknown',
            $disposition->id,
        ));
    }

    private function resolve(
        LessonFinancialDisposition $disposition,
        Lesson $lesson,
        ?InstructorEarning $earning,
        ?User $admin,
        string $action,
        ?InstructorEarningStatus $previousStatus,
        bool $applied,
    ): LessonEarningReconciliationResult {
        $disposition->forceFill([
            'instructor_earning_id' => $earning?->id ?? $disposition->instructor_earning_id,
            'processing_status' => LessonFinancialDispositionStatus::Resolved,
            'reason_code' => 'earning_reconciled_'.$action,
            'resolved_at' => now()->toImmutable()->utc(),
            'resolved_by' => $admin?->id ?? $disposition->resolved_by,
        ])->save();

        $properties = [
            'lesson_id' => $disposition->lesson_id,
            'booking_id' => $disposition->booking_id,
            'disposition_id' => $disposition->id,
            'action' => $action,
            'earning_id' => $earning?->id,
            'previous_earning_status' => $previousStatus?->value,
            'new_earning_status' => $earning?->status->value,
            'amount_minor' => $earning?->earning_amount_minor,
            'currency' => $earning?->currency_code,
            'outcome' => $lesson->outcome->value,
        ];

        $admin !== null
            ? $this->audit->logUser($admin, self::LOG_NAME, 'lesson_earning_reconciled', sprintf('Lesson %s earning reconciliation executed (%s).', $lesson->id, $action), $disposition, $properties)
            : $this->audit->logSystem(self::LOG_NAME, 'lesson_earning_reconciled', sprintf('Lesson %s earning reconciliation executed (%s).', $lesson->id, $action), $disposition, $properties);

        LessonEarningReconciled::dispatch($disposition, $earning, $action);

        return new LessonEarningReconciliationResult($disposition, $earning, $action, $applied);
    }

    /** A decision, not a failure: park for a human, committed. */
    private function defer(LessonFinancialDisposition $disposition, ?User $admin, string $reason, ?string $detail = null): LessonEarningReconciliationResult
    {
        $disposition->forceFill([
            'processing_status' => LessonFinancialDispositionStatus::ManualReview,
            'admin_hold' => true,
            'reason_code' => $reason,
        ])->save();

        $this->audit->logSystem(
            self::LOG_NAME,
            'lesson_earning_reconciliation_deferred',
            sprintf('Lesson %s earning reconciliation deferred to manual review (%s).', $disposition->lesson_id, $reason),
            $disposition,
            array_filter([
                'lesson_id' => $disposition->lesson_id,
                'booking_id' => $disposition->booking_id,
                'reason_code' => $reason,
                'detail' => $detail,
                'actor_id' => $admin?->id,
            ]),
        );

        return new LessonEarningReconciliationResult($disposition, null, 'deferred', applied: false);
    }
}
