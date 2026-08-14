<?php

declare(strict_types=1);

namespace App\Earnings\Services;

use App\Booking\Enums\BookingStatus;
use App\Earnings\Actions\CreateDemoConversionIncentiveEarningAction;
use App\Earnings\Actions\CreateInstructorEarningFromLessonAction;
use App\Earnings\Actions\TransitionInstructorEarningAction;
use App\Earnings\Contracts\InstructorCompensationResolverInterface;
use App\Earnings\Contracts\InstructorEarningRepositoryInterface;
use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Enums\CompensationExceptionCategory;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Enums\SettlementBatchStatus;
use App\Earnings\Events\InstructorEarningCreated;
use App\Earnings\Events\InstructorEarningReleased;
use App\Earnings\Events\InstructorSettlementPaid;
use App\Earnings\Exceptions\CompensationBlockedException;
use App\Earnings\Exceptions\EarningException;
use App\Earnings\Exceptions\InvalidEarningTransitionException;
use App\Lessons\Enums\LessonStatus;
use App\Models\DemoConversionIncentiveAward;
use App\Models\InstructorEarning;
use App\Models\InstructorSettlementBatch;
use App\Models\Lesson;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Settings\InstructorEarningSettings;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates instructor earnings: creation from eligible completed
 * lessons, the hold → release → settle lifecycle, dispute holds,
 * reversals, and settlement batches. Instructor compensation comes
 * exclusively from the instructor's effective-dated compensation
 * agreement (InstructorCompensationResolver) — it is never
 * calculated from the student-facing price, which does not enter this
 * service's compensation path at all. All money is integer minor units;
 * no floats are ever stored. No student wallet is touched and no
 * external payout is executed anywhere in this service.
 */
final class InstructorEarningService implements InstructorEarningServiceInterface
{
    private const string LOG_NAME = 'instructor_earnings';

    public function __construct(
        private readonly InstructorEarningRepositoryInterface $earnings,
        private readonly CreateInstructorEarningFromLessonAction $createAction,
        private readonly CreateDemoConversionIncentiveEarningAction $createIncentiveAction,
        private readonly TransitionInstructorEarningAction $transition,
        private readonly InstructorCompensationResolverInterface $resolver,
        private readonly CompensationExceptionService $exceptions,
        private readonly AuditTrailService $audit,
        private readonly InstructorEarningSettings $settings,
    ) {}

    public function createFromLesson(Lesson $lesson): ?InstructorEarning
    {
        if (! $this->settings->earnings_enabled) {
            return null;
        }

        $existing = $this->earnings->findForLesson($lesson);

        if ($existing !== null) {
            // A dispute resolved by re-completion puts the parked earning
            // back on hold; anything else is a plain idempotent hit.
            if ($existing->status === InstructorEarningStatus::DisputedHold
                && $lesson->status === LessonStatus::Completed) {
                $existing = $this->transition->execute($existing, InstructorEarningStatus::PendingHold);
                $this->audit->logSystem(self::LOG_NAME, 'earning_dispute_resolved', sprintf('Earning %s restored to hold after dispute resolution.', $existing->id), $existing);
            }

            return $existing;
        }

        if (($reason = $this->ineligibilityReason($lesson)) !== null) {
            $this->audit->logSystem(self::LOG_NAME, 'earning_skipped', sprintf('No earning for lesson %s: %s', $lesson->id, $reason), $lesson);

            // A lesson that was queued for recovery but has since left the
            // eligible state can never earn — stop retrying, stay visible.
            $this->exceptions->markPermanentlyIneligible($lesson, 'Lesson is no longer eligible: '.$reason.'.');

            return null;
        }

        // Compensation comes exclusively from the agreement in force at
        // the lesson's SCHEDULED start — the resolver has no
        // student-price input. Categorized blocks land in
        // the compensation-exception queue for the retry sweep; benign
        // skips (periodic basis, demo policy none) return null.
        try {
            $resolution = $this->resolver->resolveForLesson($lesson);
        } catch (CompensationBlockedException $blocked) {
            $this->exceptions->record($lesson, $blocked->category, $blocked->getMessage());

            return null;
        }

        if ($resolution === null) {
            return null;
        }

        try {
            $earning = $this->createAction->execute(
                $lesson,
                $resolution,
                $lesson->completed_at?->addDays($this->settings->hold_days),
            );
        } catch (UniqueConstraintViolationException) {
            // A concurrent worker created this lesson's earning between our
            // idempotency check and the insert — that is the idempotent
            // replay, never a transient failure (which would wrongly
            // re-open a just-resolved compensation exception).
            $existing = $this->earnings->findForLesson($lesson);

            if ($existing !== null) {
                $this->exceptions->resolve($lesson, $existing);

                return $existing;
            }

            $this->exceptions->record($lesson, CompensationExceptionCategory::TransientFailure, 'Earning creation failed transiently and will be retried automatically.');

            return null;
        } catch (\Throwable $failure) {
            // Unexpected infrastructure failure — queue for a separate
            // transient retry rather than losing the lesson silently.
            $this->exceptions->record($lesson, CompensationExceptionCategory::TransientFailure, 'Earning creation failed transiently and will be retried automatically.');

            report($failure);

            return null;
        }

        $this->exceptions->resolve($lesson, $earning);

        $this->audit->logSystem(
            self::LOG_NAME,
            'earning_created',
            sprintf('Earning of %d %s (minor units) created for lesson %s.', $earning->earning_amount_minor, $earning->currency_code, $lesson->id),
            $earning,
        );

        InstructorEarningCreated::dispatch($earning);

        return $earning;
    }

    public function createForReconciliation(Lesson $lesson, ?User $actor = null, ?string $reasonCode = null): ?InstructorEarning
    {
        if (! $this->settings->earnings_enabled) {
            return null;
        }

        $existing = $this->earnings->findForLesson($lesson);

        if ($existing !== null) {
            return $existing; // idempotent — one earning per lesson, ever
        }

        if ($lesson->instructor_id === null) {
            throw new EarningException('The lesson has no instructor to compensate.');
        }

        $booking = $lesson->booking;

        if ($booking === null || $booking->status === BookingStatus::Cancelled) {
            throw new EarningException('A cancelled or missing booking cannot grow a reconciliation earning.');
        }

        if (! $booking->payment_status->permitsDelivery()) {
            throw new EarningException('The booking payment is not settled — no compensation applies.');
        }

        // Same resolver, same snapshot, same creation action as
        // createFromLesson — instructor pay comes exclusively from the
        // agreement in force at the lesson's scheduled start, never from
        // student price. CompensationBlockedException propagates: an
        // explicitly approved reconciliation must not silently queue.
        $resolution = $this->resolver->resolveForLesson($lesson);

        if ($resolution === null) {
            return null; // benign: demo policy none / periodic basis
        }

        try {
            $earning = $this->createAction->execute($lesson, $resolution, now()->addDays($this->settings->hold_days));
        } catch (UniqueConstraintViolationException) {
            // A concurrent worker won the insert — idempotent replay.
            return $this->earnings->findForLesson($lesson);
        }

        $this->log(
            $actor,
            'earning_created',
            sprintf('Reconciliation earning of %d %s (minor units) created for lesson %s.', $earning->earning_amount_minor, $earning->currency_code, $lesson->id),
            $earning,
            array_filter(['reconciliation_reason' => $reasonCode]),
        );

        InstructorEarningCreated::dispatch($earning);

        return $earning;
    }

    public function createDemoConversionIncentive(DemoConversionIncentiveAward $award, Lesson $paidLesson, Lesson $demoLesson): ?InstructorEarning
    {
        if (! $this->settings->earnings_enabled) {
            return null;
        }

        $existing = $this->earnings->findBySource(CreateDemoConversionIncentiveEarningAction::sourceType(), $award->id);

        if ($existing !== null) {
            return $existing;
        }

        try {
            $earning = $this->createIncentiveAction->execute(
                $award,
                $paidLesson,
                $demoLesson,
                $paidLesson->completed_at?->addDays($this->settings->hold_days),
            );
        } catch (UniqueConstraintViolationException) {
            // A concurrent worker created this award's earning between
            // our idempotency check and the insert — idempotent replay.
            return $this->earnings->findBySource(CreateDemoConversionIncentiveEarningAction::sourceType(), $award->id);
        }

        $this->audit->logSystem(
            self::LOG_NAME,
            'demo_conversion_incentive_earning_created',
            sprintf('Demo-conversion incentive earning of %d %s (minor units) created for award %s.', $earning->earning_amount_minor, $earning->currency_code, $award->id),
            $earning,
        );

        InstructorEarningCreated::dispatch($earning);

        return $earning;
    }

    public function release(InstructorEarning $earning, ?User $actor = null, bool $override = false): InstructorEarning
    {
        if (! $override && $earning->hold_until !== null && $earning->hold_until->isFuture()) {
            throw new EarningException('The hold period has not lapsed yet — an admin override is required to release early.');
        }

        $earning = $this->transition->execute($earning, InstructorEarningStatus::Releasable, [
            'released_at' => now(),
        ]);

        $this->log($actor, 'earning_released', sprintf('Earning %s released.', $earning->id), $earning);

        InstructorEarningReleased::dispatch($earning);

        return $earning;
    }

    public function reverse(InstructorEarning $earning, ?User $actor = null, ?string $reason = null): InstructorEarning
    {
        if ($earning->settlement_batch_id !== null) {
            throw new EarningException('This earning is assigned to a settlement batch — cancel the batch first.');
        }

        $earning = $this->transition->execute($earning, InstructorEarningStatus::Reversed, [
            'reversed_at' => now(),
            'notes' => $reason,
        ]);

        $this->log($actor, 'earning_reversed', sprintf('Earning %s reversed.', $earning->id), $earning, array_filter(['reason' => $reason]));

        return $earning;
    }

    public function holdRestore(InstructorEarning $earning, ?User $actor = null): InstructorEarning
    {
        if ($earning->status === InstructorEarningStatus::PendingHold) {
            return $earning; // idempotent — already restored
        }

        $earning = $this->transition->execute($earning, InstructorEarningStatus::PendingHold);

        $this->log($actor, 'earning_dispute_resolved', sprintf('Earning %s restored to hold after dispute resolution.', $earning->id), $earning);

        return $earning;
    }

    public function holdForDispute(InstructorEarning $earning): InstructorEarning
    {
        return DB::transaction(function () use ($earning): InstructorEarning {
            // A disputed earning cannot ride along in an open batch.
            if ($earning->settlement_batch_id !== null) {
                $batch = $earning->settlementBatch;

                if ($batch !== null && ! $batch->status->isTerminal()) {
                    $this->detachFromBatch($earning, $batch);
                }
            }

            $earning = $this->transition->execute($earning, InstructorEarningStatus::DisputedHold);

            $this->audit->logSystem(self::LOG_NAME, 'earning_dispute_hold', sprintf('Earning %s parked while its lesson is disputed.', $earning->id), $earning);

            return $earning;
        });
    }

    public function releaseDue(): int
    {
        if (! $this->settings->auto_release_enabled) {
            return 0;
        }

        $released = 0;

        foreach ($this->earnings->dueForRelease(now()) as $earning) {
            try {
                $this->release($earning);
                $released++;
            } catch (EarningException) {
                // A concurrent transition beat the sweep — next run re-checks.
            }
        }

        return $released;
    }

    public function createSettlementBatch(
        int $instructorId,
        string $currencyCode,
        ?CarbonInterface $periodStart = null,
        ?CarbonInterface $periodEnd = null,
        ?User $actor = null,
        ?string $notes = null,
    ): InstructorSettlementBatch {
        // Canonical financial lock order: the instructor's user row is
        // the owner lock every financial writer for this
        // instructor takes first — settlement drafting and withdrawal
        // reservation serialize here, so the settleable set can never
        // change between the eligibility decision and the assignment.
        [$batch, $count, $total] = DB::transaction(function () use ($instructorId, $currencyCode, $periodStart, $periodEnd, $notes): array {
            User::query()->whereKey($instructorId)->lockForUpdate()->first();

            // The final eligibility decision is made on locked rows —
            // never on a stale pre-lock read.
            $earnings = $this->earnings->settleable($instructorId, $currencyCode, $periodStart, $periodEnd, forUpdate: true);

            if ($earnings->isEmpty()) {
                throw new EarningException('No releasable, unassigned earnings match this instructor, currency, and period.');
            }

            $total = (int) $earnings->sum('earning_amount_minor');

            if ($total <= 0) {
                throw new EarningException('A settlement batch total must be positive.');
            }

            $minimum = $this->settings->minimum_settlement_amount_minor;

            if ($minimum !== null && $total < $minimum) {
                throw new EarningException(sprintf('Batch total %d is below the minimum settlement amount %d.', $total, $minimum));
            }

            $batch = InstructorSettlementBatch::query()->create([
                'instructor_id' => $instructorId,
                'currency_code' => $currencyCode,
                'total_amount_minor' => $total,
                'status' => SettlementBatchStatus::Draft,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'notes' => $notes,
            ]);

            // Guarded assignment: only rows that are still releasable and
            // unassigned may enter the batch, and every locked row must
            // make it — anything else means the invariant broke mid-flight.
            $assigned = InstructorEarning::query()
                ->whereIn('id', $earnings->pluck('id'))
                ->where('status', InstructorEarningStatus::Releasable)
                ->whereNull('settlement_batch_id')
                ->update(['settlement_batch_id' => $batch->id]);

            if ($assigned !== $earnings->count()) {
                throw new EarningException('Settlement assignment integrity check failed — the eligible earnings changed mid-transaction.');
            }

            return [$batch, $earnings->count(), $total];
        });

        $this->log($actor, 'settlement_batch_created', sprintf('Settlement batch %s drafted: %d earning(s), %d %s (minor units).', $batch->batch_reference, $count, $total, $currencyCode), $batch);

        return $batch;
    }

    public function approveSettlementBatch(InstructorSettlementBatch $batch, User $actor): InstructorSettlementBatch
    {
        $this->transitionBatch($batch, SettlementBatchStatus::Approved, [
            'approved_at' => now(),
            'approved_by' => $actor->id,
        ]);

        $this->log($actor, 'settlement_batch_approved', sprintf('Settlement batch %s approved.', $batch->batch_reference), $batch);

        return $batch;
    }

    public function markSettlementBatchPaid(InstructorSettlementBatch $batch, User $actor, ?string $paymentReference = null): InstructorSettlementBatch
    {
        $batch = DB::transaction(function () use ($batch, $actor, $paymentReference): InstructorSettlementBatch {
            $this->transitionBatch($batch, SettlementBatchStatus::Paid, [
                'paid_at' => now(),
                'payment_reference' => $paymentReference,
            ]);

            foreach ($batch->earnings()->get() as $earning) {
                $this->transition->execute($earning, InstructorEarningStatus::Settled, [
                    'settled_at' => now(),
                ]);
            }

            $this->log($actor, 'settlement_batch_paid', sprintf('Settlement batch %s marked paid manually.', $batch->batch_reference), $batch, array_filter(['payment_reference' => $paymentReference]));

            return $batch;
        });

        InstructorSettlementPaid::dispatch($batch);

        return $batch;
    }

    public function cancelSettlementBatch(InstructorSettlementBatch $batch, User $actor, ?string $reason = null): InstructorSettlementBatch
    {
        if (! in_array($batch->status, [SettlementBatchStatus::Draft, SettlementBatchStatus::Failed], strict: true)) {
            throw new EarningException('Only draft or failed batches can be cancelled.');
        }

        $batch = DB::transaction(function () use ($batch, $reason): InstructorSettlementBatch {
            // Earnings stay releasable and simply return to the pool.
            $batch->earnings()->update(['settlement_batch_id' => null]);

            $batch->fill([
                'status' => SettlementBatchStatus::Cancelled,
                'notes' => $reason ?? $batch->notes,
            ])->save();

            return $batch;
        });

        $this->log($actor, 'settlement_batch_cancelled', sprintf('Settlement batch %s cancelled; earnings returned to the pool.', $batch->batch_reference), $batch, array_filter(['reason' => $reason]));

        return $batch;
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /** Null when eligible; otherwise a short reason for the audit trail. */
    private function ineligibilityReason(Lesson $lesson): ?string
    {
        if ($lesson->status !== LessonStatus::Completed || $lesson->completed_at === null) {
            return 'lesson is not completed';
        }

        if ($lesson->instructor_id === null) {
            return 'lesson has no instructor';
        }

        $booking = $lesson->booking;

        if ($booking === null) {
            return 'booking is missing';
        }

        if (! in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::Completed], strict: true)) {
            return 'booking is not confirmed/completed';
        }

        // permitsDelivery() rather than a status list: a package-funded
        // lesson is prepaid and therefore normally compensable. Getting
        // this wrong is not merely a skipped earning — createFromLesson()
        // treats an ineligibility reason as PERMANENT and closes the
        // recovery queue against the lesson.
        if (! $booking->payment_status->permitsDelivery()) {
            return 'booking payment is not settled';
        }

        return null;
    }

    /** @param array<string, mixed> $extra */
    private function transitionBatch(InstructorSettlementBatch $batch, SettlementBatchStatus $next, array $extra = []): void
    {
        if (! $batch->status->canTransitionTo($next)) {
            throw InvalidEarningTransitionException::betweenBatchStatuses($batch->status, $next);
        }

        $batch->fill([...$extra, 'status' => $next])->save();
    }

    private function detachFromBatch(InstructorEarning $earning, InstructorSettlementBatch $batch): void
    {
        $earning->settlement_batch_id = null;
        $earning->save();

        $batch->total_amount_minor = (int) $batch->earnings()->sum('earning_amount_minor');
        $batch->save();
    }

    /** @param array<string, mixed> $properties */
    private function log(?User $actor, string $event, string $description, mixed $subject, array $properties = []): void
    {
        $actor !== null
            ? $this->audit->logUser($actor, self::LOG_NAME, $event, $description, $subject, $properties)
            : $this->audit->logSystem(self::LOG_NAME, $event, $description, $subject, $properties);
    }
}
