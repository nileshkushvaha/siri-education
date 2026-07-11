<?php

declare(strict_types=1);

namespace App\Earnings\Services;

use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Earnings\Actions\CreateInstructorEarningFromLessonAction;
use App\Earnings\Actions\TransitionInstructorEarningAction;
use App\Earnings\Contracts\InstructorEarningRepositoryInterface;
use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\DTOs\EarningCalculation;
use App\Earnings\Enums\EarningCalculationType;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Enums\SettlementBatchStatus;
use App\Earnings\Events\InstructorEarningCreated;
use App\Earnings\Events\InstructorEarningReleased;
use App\Earnings\Events\InstructorSettlementPaid;
use App\Earnings\Exceptions\EarningException;
use App\Earnings\Exceptions\InvalidEarningTransitionException;
use App\Lessons\Enums\LessonStatus;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\InstructorEarning;
use App\Models\InstructorSettlementBatch;
use App\Models\Lesson;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Settings\InstructorEarningSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates instructor earnings: creation from eligible completed
 * lessons, the hold → release → settle lifecycle, dispute holds,
 * reversals, and settlement batches. Instructor earnings are NOT the
 * student price — the platform rule (percentage/fixed) computes them
 * from the booking's paid snapshot, and the student amount / platform
 * margin stay admin-only. All money is integer minor units; no floats
 * are ever stored. No student wallet is touched and no external payout
 * is executed anywhere in this service.
 */
final class InstructorEarningService implements InstructorEarningServiceInterface
{
    private const string LOG_NAME = 'instructor_earnings';

    public function __construct(
        private readonly InstructorEarningRepositoryInterface $earnings,
        private readonly CreateInstructorEarningFromLessonAction $createAction,
        private readonly TransitionInstructorEarningAction $transition,
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

            return null;
        }

        $calculation = $this->calculate($lesson->booking);

        if (is_string($calculation)) {
            // Calculation blocked — needs a manual admin decision; never guess money.
            $this->audit->logSystem(self::LOG_NAME, 'earning_calculation_blocked', sprintf('Earning for lesson %s needs manual handling: %s', $lesson->id, $calculation), $lesson);

            return null;
        }

        $earning = $this->createAction->execute(
            $lesson,
            $calculation,
            $lesson->completed_at?->addDays($this->settings->hold_days),
        );

        $this->audit->logSystem(
            self::LOG_NAME,
            'earning_created',
            sprintf('Earning of %d %s (minor units) created for lesson %s.', $earning->earning_amount_minor, $earning->currency_code, $lesson->id),
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
        $earnings = $this->earnings->settleable($instructorId, $currencyCode, $periodStart, $periodEnd);

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

        $batch = DB::transaction(function () use ($instructorId, $currencyCode, $periodStart, $periodEnd, $notes, $earnings, $total): InstructorSettlementBatch {
            $batch = InstructorSettlementBatch::query()->create([
                'instructor_id' => $instructorId,
                'currency_code' => $currencyCode,
                'total_amount_minor' => $total,
                'status' => SettlementBatchStatus::Draft,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'notes' => $notes,
            ]);

            InstructorEarning::query()
                ->whereIn('id', $earnings->pluck('id'))
                ->update(['settlement_batch_id' => $batch->id]);

            return $batch;
        });

        $this->log($actor, 'settlement_batch_created', sprintf('Settlement batch %s drafted: %d earning(s), %d %s (minor units).', $batch->batch_reference, $earnings->count(), $total, $currencyCode), $batch);

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

        if (! in_array($booking->payment_status, [BookingPaymentStatus::Paid, BookingPaymentStatus::NotRequired], strict: true)) {
            return 'booking payment is not settled';
        }

        return null;
    }

    /**
     * Integer-minor-unit calculation, or a block reason string when the
     * amount cannot be derived safely (never guess money).
     */
    private function calculate(Booking $booking): EarningCalculation|string
    {
        [$studentMinor, $currencyCode] = $this->resolveStudentAmount($booking);

        $type = EarningCalculationType::tryFrom($this->settings->default_calculation_type)
            ?? EarningCalculationType::Percentage;

        // Percentage needs a student amount; free/demo lessons fall back
        // to the fixed rate when one is configured.
        if ($type === EarningCalculationType::Percentage && $studentMinor === null) {
            $type = EarningCalculationType::Fixed;
        }

        if ($type === EarningCalculationType::Percentage) {
            $percentage = $this->settings->default_percentage;

            if ($percentage < 0 || $percentage > 100) {
                return sprintf('default_percentage %s is outside 0-100', $percentage);
            }

            $earning = (int) floor($studentMinor * $percentage / 100);

            return new EarningCalculation(
                type: EarningCalculationType::Percentage,
                currencyCode: $currencyCode,
                earningAmountMinor: $earning,
                studentAmountMinor: $studentMinor,
                platformMarginMinor: $studentMinor - $earning,
                value: $percentage,
            );
        }

        $fixed = $this->settings->default_fixed_amount_minor;

        if ($fixed === null || $fixed < 0) {
            return 'no student amount and no fixed rate configured';
        }

        $fixedCurrency = $currencyCode ?? $this->settings->default_currency_code;

        if ($fixedCurrency === null) {
            return 'fixed rate has no currency (set default_currency_code)';
        }

        if ($studentMinor !== null && $fixed > $studentMinor) {
            return 'fixed rate exceeds the student paid amount (negative margin)';
        }

        return new EarningCalculation(
            type: EarningCalculationType::Fixed,
            currencyCode: $fixedCurrency,
            earningAmountMinor: $fixed,
            studentAmountMinor: $studentMinor,
            platformMarginMinor: $studentMinor !== null ? $studentMinor - $fixed : null,
            value: null,
        );
    }

    /**
     * The student paid snapshot, admin-only: the captured BookingPayment
     * row is authoritative (already integer minor units); the booking's
     * decimal price is the fallback, converted via the currency's minor
     * units. Free/demo bookings (payment not required) return null.
     *
     * @return array{0: ?int, 1: ?string}
     */
    private function resolveStudentAmount(Booking $booking): array
    {
        if ($booking->payment_status !== BookingPaymentStatus::Paid) {
            return [null, null];
        }

        $captured = $booking->payments()
            ->where('status', BookingPaymentRecordStatus::Captured)
            ->latest('paid_at')
            ->first();

        if ($captured !== null) {
            return [(int) $captured->amount_minor, $captured->currency_code];
        }

        if ($booking->price === null || $booking->currency === null) {
            return [null, null];
        }

        $minorUnits = Currency::query()->where('code', $booking->currency)->value('minor_units') ?? 2;

        // decimal:2 cast yields a string — integer math on the split parts
        // avoids float drift entirely.
        [$whole, $fraction] = array_pad(explode('.', (string) $booking->price, 2), 2, '0');
        $fraction = substr(str_pad($fraction, $minorUnits, '0'), 0, $minorUnits);

        return [((int) $whole) * (10 ** $minorUnits) + (int) $fraction, $booking->currency];
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
