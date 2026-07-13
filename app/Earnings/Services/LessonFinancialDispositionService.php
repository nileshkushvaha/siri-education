<?php

declare(strict_types=1);

namespace App\Earnings\Services;

use App\Booking\Enums\BookingPaymentStatus;
use App\Earnings\Contracts\InstructorEarningRepositoryInterface;
use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Contracts\LessonFinancialDispositionServiceInterface;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Enums\LessonFinancialDispositionStatus;
use App\Earnings\Enums\LessonInstructorDisposition;
use App\Earnings\Enums\LessonStudentDisposition;
use App\Earnings\Exceptions\EarningException;
use App\Lessons\Enums\LessonOutcome;
use App\Models\InstructorEarning;
use App\Models\Lesson;
use App\Models\LessonFinancialDisposition;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Settings\InstructorEarningSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Classification only — this service never moves money. It reuses the
 * existing mechanisms exclusively: holds go through
 * InstructorEarningService::holdForDispute() (DisputedHold detaches
 * from open settlement batches and is excluded from the settleable
 * scope), completed-lesson earnings stay with the Phase 14
 * LessonCompleted listener, and cancellations stay with the booking
 * cancellation refund/reversal pipeline. Settled or paid earnings are
 * never touched — conflicts route to manual review with an admin hold.
 */
final class LessonFinancialDispositionService implements LessonFinancialDispositionServiceInterface
{
    private const string LOG_NAME = 'instructor_earnings';

    public function __construct(
        private readonly InstructorEarningRepositoryInterface $earnings,
        private readonly InstructorEarningServiceInterface $earningService,
        private readonly AuditTrailService $audit,
        private readonly InstructorEarningSettings $settings,
    ) {}

    public function classify(Lesson $lesson, LessonOutcome $outcome): ?LessonFinancialDisposition
    {
        if (! $this->settings->financial_disposition_enabled || $outcome === LessonOutcome::Pending) {
            return null;
        }

        return DB::transaction(function () use ($lesson, $outcome): LessonFinancialDisposition {
            $existing = $this->lockForLesson($lesson);

            // Exactly-once: a replayed finalization event for the same
            // outcome changes nothing; a different outcome only ever
            // arrives via the override event → reevaluate().
            if ($existing !== null) {
                return $existing;
            }

            $disposition = new LessonFinancialDisposition([
                'lesson_id' => $lesson->id,
                'booking_id' => $lesson->booking_id,
                'version' => 1,
            ]);

            $this->applyEvaluation($disposition, $lesson, $outcome);
            $disposition->save();

            $this->audit->logSystem(
                self::LOG_NAME,
                'lesson_financial_disposition_classified',
                sprintf('Lesson %s classified: student %s / instructor %s (%s).', $lesson->id, $disposition->student_disposition->value, $disposition->instructor_disposition->value, $disposition->processing_status->value),
                $disposition,
                [
                    'lesson_id' => $lesson->id,
                    'booking_id' => $lesson->booking_id,
                    'outcome' => $outcome->value,
                    'reason_code' => $disposition->reason_code,
                ],
            );

            return $disposition;
        });
    }

    public function reevaluate(Lesson $lesson, LessonOutcome $previousOutcome, LessonOutcome $newOutcome, string $overrideReason): ?LessonFinancialDisposition
    {
        if (! $this->settings->financial_disposition_enabled) {
            return null;
        }

        return DB::transaction(function () use ($lesson, $previousOutcome, $newOutcome, $overrideReason): LessonFinancialDisposition {
            $disposition = $this->lockForLesson($lesson);

            if ($disposition === null) {
                // Classified for the first time via the override (e.g. the
                // bridge was enabled after the original finalization).
                $disposition = new LessonFinancialDisposition([
                    'lesson_id' => $lesson->id,
                    'booking_id' => $lesson->booking_id,
                    'version' => 0,
                ]);
            } else {
                // History is preserved — never rewritten, never deleted.
                $disposition->history = [...($disposition->history ?? []), $disposition->snapshotForHistory()];
            }

            $disposition->version = $disposition->version + 1;
            $disposition->forceFill(['resolved_at' => null, 'resolved_by' => null, 'resolution_reason' => null]);

            // Phase 17F: a refund already went out for the previous outcome.
            // Never silently debit the wallet and never delete the refund —
            // the record keeps its ledger linkage and a human reconciles.
            if ($disposition->refund_ledger_entry_id !== null) {
                $disposition->fill([
                    'outcome' => $newOutcome,
                    'processing_status' => LessonFinancialDispositionStatus::ManualReview,
                    'admin_hold' => true,
                    'reason_code' => 'refund_reconciliation_required',
                    'evaluated_at' => now()->toImmutable()->utc(),
                ])->save();
            } else {
                $this->applyEvaluation($disposition, $lesson, $newOutcome, override: true);
                $disposition->save();
            }

            $this->audit->logSystem(
                self::LOG_NAME,
                'lesson_financial_disposition_reevaluated',
                sprintf('Lesson %s disposition re-evaluated after override (%s → %s).', $lesson->id, $previousOutcome->value, $newOutcome->value),
                $disposition,
                [
                    'lesson_id' => $lesson->id,
                    'previous_outcome' => $previousOutcome->value,
                    'new_outcome' => $newOutcome->value,
                    'override_reason' => $overrideReason,
                    'version' => $disposition->version,
                    'reason_code' => $disposition->reason_code,
                ],
            );

            return $disposition;
        });
    }

    // ── Admin resolution ──────────────────────────────────────────────────

    public function approve(LessonFinancialDisposition $disposition, User $admin, string $reason): LessonFinancialDisposition
    {
        return $this->resolve($disposition, $admin, $reason, 'lesson_financial_disposition_approved', function (LessonFinancialDisposition $d): void {
            $d->processing_status = LessonFinancialDispositionStatus::Resolved;
            $d->admin_hold = false;
            $d->reason_code = 'admin_approved';
        });
    }

    public function keepOnHold(LessonFinancialDisposition $disposition, User $admin, string $reason): LessonFinancialDisposition
    {
        return $this->resolve($disposition, $admin, $reason, 'lesson_financial_disposition_held', function (LessonFinancialDisposition $d): void {
            $d->processing_status = LessonFinancialDispositionStatus::Held;
            $d->admin_hold = true;
            $d->reason_code = 'admin_hold';
        });
    }

    public function reject(LessonFinancialDisposition $disposition, User $admin, string $reason): LessonFinancialDisposition
    {
        return $this->resolve($disposition, $admin, $reason, 'lesson_financial_disposition_rejected', function (LessonFinancialDisposition $d): void {
            $d->processing_status = LessonFinancialDispositionStatus::Resolved;
            $d->student_disposition = LessonStudentDisposition::None;
            $d->instructor_disposition = LessonInstructorDisposition::NoEarning;
            $d->reason_code = 'admin_rejected';
        });
    }

    public function markReadyForRefund(LessonFinancialDisposition $disposition, User $admin, string $reason): LessonFinancialDisposition
    {
        // An explicit approval IS the human decision an admin hold was
        // waiting for — it lifts the hold so execution can proceed.
        return $this->resolve($disposition, $admin, $reason, 'lesson_financial_disposition_refund_ready', function (LessonFinancialDisposition $d): void {
            $d->processing_status = LessonFinancialDispositionStatus::Ready;
            $d->admin_hold = false;
            $d->student_disposition = LessonStudentDisposition::FullWalletRefundRequired;
            $d->reason_code = 'refund_execution_ready';
        });
    }

    public function markReadyForEarningReconciliation(LessonFinancialDisposition $disposition, User $admin, string $reason): LessonFinancialDisposition
    {
        return $this->resolve($disposition, $admin, $reason, 'lesson_financial_disposition_earning_ready', function (LessonFinancialDisposition $d): void {
            $d->processing_status = LessonFinancialDispositionStatus::Ready;
            $d->admin_hold = false;
            $d->reason_code = 'earning_reconciliation_required';
        });
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * The disposition matrix. Holds are placed here (via the existing
     * earning mechanism); settled/paid conflicts route to manual review
     * with an admin hold — settled money is never altered silently.
     */
    private function applyEvaluation(LessonFinancialDisposition $disposition, Lesson $lesson, LessonOutcome $outcome, bool $override = false): void
    {
        $booking = $lesson->booking;
        $paid = $booking?->payment_status === BookingPaymentStatus::Paid;
        $earning = $this->earnings->findForLesson($lesson);

        [$student, $instructor, $status, $reason] = match ($outcome) {
            LessonOutcome::Completed => $paid
                ? [LessonStudentDisposition::None, LessonInstructorDisposition::ExistingCompletionEarning, LessonFinancialDispositionStatus::NoAction, 'completed_paid']
                : [LessonStudentDisposition::None, LessonInstructorDisposition::NoEarning, LessonFinancialDispositionStatus::NoAction, 'completed_demo'],
            LessonOutcome::StudentNoShow => match ($this->settings->student_no_show_compensation_policy) {
                'normal_earning' => [LessonStudentDisposition::None, LessonInstructorDisposition::CompensationReviewRequired, LessonFinancialDispositionStatus::Ready, 'student_no_show_earning_reconciliation_required'],
                'no_earning' => [LessonStudentDisposition::None, LessonInstructorDisposition::NoEarning, LessonFinancialDispositionStatus::NoAction, 'student_no_show_no_compensation'],
                default => [LessonStudentDisposition::None, LessonInstructorDisposition::CompensationReviewRequired, LessonFinancialDispositionStatus::ManualReview, 'student_no_show_policy_review'],
            },
            LessonOutcome::InstructorNoShow => [LessonStudentDisposition::FullWalletRefundRequired, LessonInstructorDisposition::NoEarning, LessonFinancialDispositionStatus::Ready, 'instructor_no_show_refund_required'],
            LessonOutcome::BothAbsent => match ($this->settings->both_absent_financial_policy) {
                'refund' => [LessonStudentDisposition::FullWalletRefundRequired, LessonInstructorDisposition::NoEarning, LessonFinancialDispositionStatus::Ready, 'both_absent_refund'],
                'forfeit' => [LessonStudentDisposition::None, LessonInstructorDisposition::NoEarning, LessonFinancialDispositionStatus::NoAction, 'both_absent_forfeit'],
                default => [LessonStudentDisposition::PolicyReviewRequired, LessonInstructorDisposition::CompensationReviewRequired, LessonFinancialDispositionStatus::ManualReview, 'both_absent_policy_review'],
            },
            LessonOutcome::TechnicalIssue => [LessonStudentDisposition::PolicyReviewRequired, LessonInstructorDisposition::HoldExistingEarning, LessonFinancialDispositionStatus::Held, 'technical_issue_hold'],
            LessonOutcome::Cancelled => [LessonStudentDisposition::ExistingCancellationFlow, LessonInstructorDisposition::ExistingCancellationFlow, LessonFinancialDispositionStatus::NoAction, 'cancellation_pipeline'],
            LessonOutcome::Pending => throw new EarningException('A pending outcome has no financial disposition.'),
        };

        // Unpaid demo money never needs a refund — students paid nothing.
        if (! $paid && $student === LessonStudentDisposition::FullWalletRefundRequired) {
            [$student, $status, $reason] = [LessonStudentDisposition::None, $instructor === LessonInstructorDisposition::NoEarning ? LessonFinancialDispositionStatus::NoAction : $status, $reason.'_unpaid'];
        }

        // ── Earning conflicts (holds, settled money, reconciliation) ──
        if ($earning !== null && $outcome !== LessonOutcome::Completed && $outcome !== LessonOutcome::Cancelled) {
            if ($earning->status->isTerminal()) {
                // Settled/reversed money is never altered silently.
                $disposition->admin_hold = true;
                [$status, $reason] = [LessonFinancialDispositionStatus::ManualReview, 'settled_earning_conflict'];
            } else {
                $this->holdEarning($earning);
                $instructor = LessonInstructorDisposition::HoldExistingEarning;

                if ($status === LessonFinancialDispositionStatus::NoAction || $status === LessonFinancialDispositionStatus::Ready) {
                    $status = LessonFinancialDispositionStatus::Held;
                }
            }
        }

        // Override to Completed with no earning yet: creation belongs to a
        // later reconciliation phase — record it, never create it here.
        if ($override && $outcome === LessonOutcome::Completed && $paid && $earning === null) {
            [$status, $reason] = [LessonFinancialDispositionStatus::ManualReview, 'earning_reconciliation_required'];
        }

        // A refund that already went out cannot be re-decided automatically.
        if ($override && $booking?->payment_status === BookingPaymentStatus::Refunded) {
            $disposition->admin_hold = true;
            [$status, $reason] = [LessonFinancialDispositionStatus::ManualReview, 'refund_already_completed'];
        }

        $disposition->fill([
            'outcome' => $outcome,
            'student_disposition' => $student,
            'instructor_disposition' => $instructor,
            'processing_status' => $status,
            'reason_code' => $reason,
            'instructor_earning_id' => $earning?->id,
            'payment_reference' => $booking?->payment_reference,
            'evaluated_at' => now()->toImmutable()->utc(),
        ]);
    }

    /** Idempotent: an earning already on DisputedHold stays exactly where it is. */
    private function holdEarning(InstructorEarning $earning): void
    {
        if ($earning->status === InstructorEarningStatus::DisputedHold) {
            return;
        }

        $this->earningService->holdForDispute($earning);
    }

    private function resolve(LessonFinancialDisposition $disposition, User $admin, string $reason, string $auditEvent, callable $apply): LessonFinancialDisposition
    {
        if (! $admin->can('resolveFinancialDisposition', $disposition->lesson)) {
            throw new AuthorizationException('You may not resolve financial dispositions.');
        }

        if (trim($reason) === '') {
            throw new EarningException('A financial-disposition decision requires a reason.');
        }

        return DB::transaction(function () use ($disposition, $admin, $reason, $auditEvent, $apply): LessonFinancialDisposition {
            $disposition = LessonFinancialDisposition::query()
                ->whereKey($disposition->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($disposition->processing_status === LessonFinancialDispositionStatus::Resolved) {
                throw new EarningException('This disposition is already resolved — an outcome override must re-open it.');
            }

            $previousStatus = $disposition->processing_status;
            $previousStudent = $disposition->student_disposition;
            $previousInstructor = $disposition->instructor_disposition;

            $apply($disposition);

            $disposition->forceFill([
                'resolved_at' => now()->toImmutable()->utc(),
                'resolved_by' => $admin->id,
                'resolution_reason' => mb_substr(trim(strip_tags($reason)), 0, 500),
            ])->save();

            $this->audit->logUser(
                $admin,
                self::LOG_NAME,
                $auditEvent,
                sprintf('Lesson %s financial disposition %s.', $disposition->lesson_id, $disposition->processing_status->value),
                $disposition,
                [
                    'lesson_id' => $disposition->lesson_id,
                    'booking_id' => $disposition->booking_id,
                    'previous_status' => $previousStatus->value,
                    'new_status' => $disposition->processing_status->value,
                    'previous_student_disposition' => $previousStudent->value,
                    'new_student_disposition' => $disposition->student_disposition->value,
                    'previous_instructor_disposition' => $previousInstructor->value,
                    'new_instructor_disposition' => $disposition->instructor_disposition->value,
                    'reason' => $reason,
                    'version' => $disposition->version,
                ],
            );

            return $disposition;
        });
    }

    private function lockForLesson(Lesson $lesson): ?LessonFinancialDisposition
    {
        return LessonFinancialDisposition::query()
            ->where('lesson_id', $lesson->id)
            ->lockForUpdate()
            ->first();
    }
}
