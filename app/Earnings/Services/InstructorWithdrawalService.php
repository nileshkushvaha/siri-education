<?php

declare(strict_types=1);

namespace App\Earnings\Services;

use App\Earnings\Contracts\InstructorWithdrawalAllocationServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalBalanceServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalServiceInterface;
use App\Earnings\Contracts\PayoutMethodSnapshotServiceInterface;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Enums\InstructorWithdrawalStatus;
use App\Earnings\Enums\PayoutMethodStatus;
use App\Earnings\Enums\WithdrawalAllocationStatus;
use App\Earnings\Events\InstructorWithdrawalApproved;
use App\Earnings\Events\InstructorWithdrawalCancelled;
use App\Earnings\Events\InstructorWithdrawalRejected;
use App\Earnings\Events\InstructorWithdrawalRequested;
use App\Earnings\Events\InstructorWithdrawalReviewStarted;
use App\Earnings\Exceptions\InvalidWithdrawalTransitionException;
use App\Earnings\Exceptions\WithdrawalException;
use App\Earnings\Support\InstructorPayoutEligibility;
use App\Models\InstructorEarning;
use App\Models\InstructorPayoutMethod;
use App\Models\InstructorWithdrawalRequest;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Settings\InstructorEarningSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns the withdrawal lifecycle. Creation is one transaction that locks
 * the instructor row (the canonical financial scope — concurrent
 * submissions serialize here), recalculates the available balance from
 * canonical rows, reserves earnings FIFO, freezes the payment
 * destination into an encrypted snapshot, and stores balance snapshots.
 * Every later transition passes the enum state machine; rejection and
 * cancellation release reservations in the same transaction. Phase 15
 * never moves money: approved is the terminal operational state here.
 */
final class InstructorWithdrawalService implements InstructorWithdrawalServiceInterface
{
    private const string LOG_NAME = 'instructor_payouts';

    public function __construct(
        private readonly InstructorWithdrawalBalanceServiceInterface $balances,
        private readonly InstructorWithdrawalAllocationServiceInterface $allocations,
        private readonly PayoutMethodSnapshotServiceInterface $snapshots,
        private readonly InstructorPayoutEligibility $eligibility,
        private readonly InstructorEarningSettings $settings,
        private readonly AuditTrailService $audit,
    ) {}

    public function requestWithdrawal(
        User $instructor,
        InstructorPayoutMethod $method,
        int $amountMinor,
        ?string $instructorNote = null,
        ?string $idempotencyKey = null,
    ): InstructorWithdrawalRequest {
        if (! $this->settings->withdrawals_enabled) {
            throw new WithdrawalException('Withdrawals are currently disabled.');
        }

        if (($reason = $this->eligibility->reasonForIneligibility($instructor)) !== null) {
            throw new WithdrawalException($reason);
        }

        if ($amountMinor <= 0) {
            throw new WithdrawalException('The withdrawal amount must be positive.');
        }

        $request = DB::transaction(function () use ($instructor, $method, $amountMinor, $instructorNote, $idempotencyKey): InstructorWithdrawalRequest {
            // Canonical financial scope lock — concurrent submissions for
            // the same instructor serialize on this row.
            User::query()->whereKey($instructor->id)->lockForUpdate()->first();

            if ($idempotencyKey !== null) {
                $existing = InstructorWithdrawalRequest::query()
                    ->where('instructor_id', $instructor->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    // A replay must be byte-for-byte the same request. The
                    // same key with a different amount or destination is a
                    // conflict, never a silent success for the old values.
                    if ($existing->amount_minor !== $amountMinor
                        || $existing->payout_method_id !== $method->id) {
                        throw new WithdrawalException('This request was already submitted with different details. Refresh the form and try again.');
                    }

                    return $existing;
                }
            }

            $active = InstructorWithdrawalRequest::query()->forInstructor($instructor->id)->active()->count();

            if ($active >= $this->settings->maximum_active_requests_per_instructor) {
                throw new WithdrawalException('You already have the maximum number of open withdrawal requests.');
            }

            // Re-read the payout method under lock — it must still be the
            // instructor's own, usable method when the request persists.
            $method = InstructorPayoutMethod::query()->whereKey($method->id)->lockForUpdate()->firstOrFail();

            if ($method->instructor_id !== $instructor->id) {
                throw new WithdrawalException('This payout method does not belong to you.');
            }

            if ($this->settings->payout_method_verification_required && $method->status !== PayoutMethodStatus::Verified) {
                throw new WithdrawalException('This payout method is not verified.');
            }

            if (! $method->status->isActive() || $method->deleted_at !== null) {
                throw new WithdrawalException('This payout method cannot be used.');
            }

            $currencyCode = $method->currency_code;

            // Deterministic FIFO pool, locked before the balance math.
            $earnings = InstructorEarning::query()
                ->withdrawable($instructor->id, $currencyCode)
                ->orderBy('released_at')
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $balance = $this->balances->calculate($instructor, $currencyCode);

            if ($amountMinor < $this->settings->minimum_withdrawal_minor) {
                throw new WithdrawalException('The amount is below the minimum withdrawal amount.');
            }

            $maximum = $this->settings->maximum_withdrawal_minor;

            if ($maximum !== null && $amountMinor > $maximum) {
                throw new WithdrawalException('The amount exceeds the maximum withdrawal amount.');
            }

            if ($amountMinor > $balance->availableMinor) {
                throw new WithdrawalException('The amount exceeds your available balance.');
            }

            $request = new InstructorWithdrawalRequest([
                'reference' => $this->generateReference(),
                'instructor_id' => $instructor->id,
                'payout_method_id' => $method->id,
                'currency_id' => $method->currency_id,
                'currency_code' => $currencyCode,
                'amount_minor' => $amountMinor,
                'fee_minor' => 0,
                'net_amount_minor' => $amountMinor,
                'available_balance_before_minor' => $balance->availableMinor,
                'available_balance_after_minor' => $balance->availableMinor - $amountMinor,
                'payout_method_type' => $method->type,
                'payout_method_label' => $method->display_label,
                'masked_identifier' => $method->masked_identifier,
                'encrypted_payout_method_snapshot' => $this->snapshots->capture($method),
                'idempotency_key' => $idempotencyKey,
                'instructor_note' => $instructorNote,
                'requested_at' => now(),
            ]);

            // Status is not mass assignable by design.
            $request->forceFill(['status' => InstructorWithdrawalStatus::Submitted])->save();

            $this->allocations->reserve($request, $earnings);

            $method->forceFill(['last_used_at' => now()])->save();

            return $request;
        });

        // Audit (and the notification pipeline hanging off it) only after
        // the financial transaction has committed — and only for a fresh
        // request, never for an idempotent replay.
        if ($request->wasRecentlyCreated) {
            $this->audit->logUser($instructor, self::LOG_NAME, 'withdrawal_requested', sprintf('Withdrawal %s requested: %d %s (minor units).', $request->reference, $request->amount_minor, $request->currency_code), $request, [
                'amount_minor' => $request->amount_minor,
                'currency' => $request->currency_code,
                'status_after' => InstructorWithdrawalStatus::Submitted->value,
            ]);

            InstructorWithdrawalRequested::dispatch($request);
        }

        return $request;
    }

    public function startReview(InstructorWithdrawalRequest $request, User $admin): InstructorWithdrawalRequest
    {
        $request = DB::transaction(fn (): InstructorWithdrawalRequest => $this->transition($this->locked($request), InstructorWithdrawalStatus::UnderReview, [
            'review_started_at' => now(),
            'review_started_by' => $admin->id,
        ]));

        $this->audit->logUser($admin, self::LOG_NAME, 'withdrawal_review_started', sprintf('Withdrawal %s review started.', $request->reference), $request);

        InstructorWithdrawalReviewStarted::dispatch($request);

        return $request;
    }

    public function approve(InstructorWithdrawalRequest $request, User $admin): InstructorWithdrawalRequest
    {
        $request = DB::transaction(function () use ($request, $admin): InstructorWithdrawalRequest {
            $request = $this->locked($request);

            if ($this->settings->withdrawal_review_required && $request->status === InstructorWithdrawalStatus::Submitted) {
                throw new WithdrawalException('This request must be reviewed before it can be approved.');
            }

            // Full financial integrity re-check on locked rows. Approval
            // never repairs a broken record — it fails atomically and the
            // request stays where it was for manual investigation.
            $this->assertApprovalIntegrity($request);

            return $this->transition($request, InstructorWithdrawalStatus::Approved, [
                'approved_at' => now(),
                'approved_by' => $admin->id,
            ]);
        });

        $this->audit->logUser($admin, self::LOG_NAME, 'withdrawal_approved', sprintf('Withdrawal %s approved; reservations retained for future payout execution.', $request->reference), $request);

        InstructorWithdrawalApproved::dispatch($request);

        return $request;
    }

    public function reject(InstructorWithdrawalRequest $request, User $admin, string $reason): InstructorWithdrawalRequest
    {
        if (trim($reason) === '') {
            throw new WithdrawalException('A rejection reason is required.');
        }

        $request = DB::transaction(function () use ($request, $admin, $reason): InstructorWithdrawalRequest {
            $request = $this->transition($this->locked($request), InstructorWithdrawalStatus::Rejected, [
                'rejected_at' => now(),
                'rejected_by' => $admin->id,
                'rejection_reason' => $reason,
            ]);

            $this->allocations->release($request);

            return $request;
        });

        $this->audit->logUser($admin, self::LOG_NAME, 'withdrawal_rejected', sprintf('Withdrawal %s rejected; reservations released.', $request->reference), $request, ['reason' => $reason]);

        InstructorWithdrawalRejected::dispatch($request);

        return $request;
    }

    public function cancelByInstructor(InstructorWithdrawalRequest $request, User $instructor): InstructorWithdrawalRequest
    {
        if (! $this->settings->instructor_cancellation_enabled) {
            throw new WithdrawalException('Cancelling withdrawal requests is currently disabled.');
        }

        if ($request->instructor_id !== $instructor->id) {
            throw new WithdrawalException('You can only cancel your own withdrawal requests.');
        }

        if (! $request->status->isInstructorCancellable()) {
            throw new WithdrawalException('This withdrawal request can no longer be cancelled.');
        }

        return $this->cancel($request, $instructor, null);
    }

    public function cancelByAdmin(InstructorWithdrawalRequest $request, User $admin, ?string $reason = null): InstructorWithdrawalRequest
    {
        if (! in_array($request->status, [InstructorWithdrawalStatus::Submitted, InstructorWithdrawalStatus::UnderReview], strict: true)) {
            throw new WithdrawalException('Only submitted or under-review requests can be cancelled.');
        }

        return $this->cancel($request, $admin, $reason);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function cancel(InstructorWithdrawalRequest $request, User $actor, ?string $reason): InstructorWithdrawalRequest
    {
        $request = DB::transaction(function () use ($request, $actor): InstructorWithdrawalRequest {
            $request = $this->transition($this->locked($request), InstructorWithdrawalStatus::Cancelled, [
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
            ]);

            $this->allocations->release($request);

            return $request;
        });

        $this->audit->logUser($actor, self::LOG_NAME, 'withdrawal_cancelled', sprintf('Withdrawal %s cancelled; reservations released.', $request->reference), $request, array_filter(['reason' => $reason]));

        InstructorWithdrawalCancelled::dispatch($request);

        return $request;
    }

    private function locked(InstructorWithdrawalRequest $request): InstructorWithdrawalRequest
    {
        return InstructorWithdrawalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Every invariant approval depends on, verified on locked rows.
     * Any breach aborts the whole transaction — no silent repair.
     */
    private function assertApprovalIntegrity(InstructorWithdrawalRequest $request): void
    {
        if ($request->amount_minor <= 0) {
            throw new WithdrawalException('Approval integrity check failed — the withdrawal amount is not positive.');
        }

        if ($request->net_amount_minor !== $request->amount_minor - $request->fee_minor) {
            throw new WithdrawalException('Approval integrity check failed — the fee and net amount no longer reconcile.');
        }

        // Reservation integrity: the reserved sum must still cover the
        // request exactly — nothing may have leaked or been released.
        $allocations = $request->allocations()
            ->where('status', WithdrawalAllocationStatus::Reserved)
            ->lockForUpdate()
            ->get();

        if ((int) $allocations->sum('amount_minor') !== $request->amount_minor) {
            throw new WithdrawalException('Reservation integrity check failed — the reserved amount no longer matches this request.');
        }

        // Every backing earning, locked: still the instructor's, still the
        // right currency, still releasable, still outside any settlement
        // batch. A dispute, reversal, or batch assignment since submission
        // blocks approval.
        $earnings = InstructorEarning::query()
            ->whereIn('id', $allocations->pluck('instructor_earning_id'))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($allocations as $allocation) {
            $earning = $earnings->get($allocation->instructor_earning_id);

            if ($earning === null
                || $earning->instructor_id !== $request->instructor_id
                || $earning->currency_code !== $request->currency_code) {
                throw new WithdrawalException('Approval integrity check failed — a reserved earning no longer belongs to this request.');
            }

            if ($earning->status !== InstructorEarningStatus::Releasable || $earning->settlement_batch_id !== null) {
                throw new WithdrawalException('Approval integrity check failed — a reserved earning is no longer available (disputed, reversed, or settlement-assigned).');
            }

            if ($allocation->currency_code !== $request->currency_code) {
                throw new WithdrawalException('Approval integrity check failed — an allocation currency does not match this request.');
            }
        }

        // The destination must still be valid under the active-method
        // policy, and the immutable snapshot present and decryptable —
        // a disabled or unreadable destination is not approvable.
        $method = $request->payoutMethod;

        if ($method === null || ! $method->status->isActive() || $method->deleted_at !== null) {
            throw new WithdrawalException('Approval integrity check failed — the payout method is no longer active.');
        }

        try {
            $snapshot = $request->encrypted_payout_method_snapshot;
        } catch (DecryptException) {
            throw new WithdrawalException('Approval integrity check failed — the payout snapshot cannot be decrypted. Verify the application encryption key.');
        }

        if (empty($snapshot)) {
            throw new WithdrawalException('Approval integrity check failed — the payout snapshot is missing.');
        }
    }

    /** @param array<string, mixed> $extra */
    private function transition(InstructorWithdrawalRequest $request, InstructorWithdrawalStatus $next, array $extra = []): InstructorWithdrawalRequest
    {
        if (! $request->status->canTransitionTo($next)) {
            throw InvalidWithdrawalTransitionException::between($request->status, $next);
        }

        $request->forceFill([...$extra, 'status' => $next])->save();

        return $request;
    }

    /** Collision-safe WD-YYYYMM-XXXXXXXX; uniqueness is DB-enforced regardless. */
    private function generateReference(): string
    {
        do {
            $reference = sprintf('WD-%s-%s', now()->format('Ym'), strtoupper(Str::random(8)));
        } while (InstructorWithdrawalRequest::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
