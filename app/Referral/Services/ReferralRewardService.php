<?php

declare(strict_types=1);

namespace App\Referral\Services;

use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Lessons\Enums\LessonOutcome;
use App\Models\BookingPayment;
use App\Models\Lesson;
use App\Models\ReferralAttribution;
use App\Models\ReferralCampaign;
use App\Models\ReferralReward;
use App\Models\User;
use App\Models\Wallet;
use App\Referral\Contracts\ReferralRewardServiceInterface;
use App\Referral\DTOs\ReferralRewardCalculationResult;
use App\Referral\Enums\ReferralRewardStatus;
use App\Referral\Enums\ReferralRewardType;
use App\Referral\Events\ReferralRewardCredited;
use App\Referral\Events\ReferralRewardCreditFailed;
use App\Referral\Events\ReferralRewardHeld;
use App\Referral\Events\ReferralRewardRejected;
use App\Referral\Events\ReferralRewardReversed;
use App\Referral\Exceptions\ReferralException;
use App\Services\AuditTrailService;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Exceptions\WalletException;
use App\Wallet\Services\WalletLedgerService;
use App\Wallet\Services\WalletService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single owner of reward money movement. Exactly one credit path
 * (creditReward — called by the eligibility listener for immediate
 * rewards and by referrals:credit-eligible-rewards for delayed ones)
 * and exactly one reversal path (reevaluateLesson). Every wallet
 * mutation goes through WalletLedgerService — never a direct ledger
 * write, never a balance column touch, never a currency conversion.
 *
 * Wallet-currency rule: the referrer's wallet is their default-currency
 * wallet (profile country default → platform default, exactly
 * WalletService::getOrCreateWallet's own resolution). A reward whose
 * currency differs is Held with reason currency_mismatch — visible for
 * Phase 19E, never converted, never silently dropped.
 *
 * Reversal rule: WalletLedgerService::reverse() requires a wallet-
 * manage actor. When invalidation arrives without one (automated
 * refund/override events), a credited reward keeps status Credited and
 * gains the visible `reversal_required` reason — Phase 19E completes
 * the reversal with a real administrator. It is never falsely marked
 * Reversed without a reversal ledger entry (DB CHECK enforces this).
 */
final class ReferralRewardService implements ReferralRewardServiceInterface
{
    private const string LOG_NAME = 'referral_rewards';

    public function __construct(
        private readonly WalletService $wallets,
        private readonly WalletLedgerService $ledger,
        private readonly AuditTrailService $auditTrail,
    ) {}

    public function calculate(ReferralCampaign $campaign, int $lessonAmountMinor, string $lessonCurrencyCode): ReferralRewardCalculationResult
    {
        if ($lessonAmountMinor < 0) {
            throw new ReferralException('A lesson amount can never be negative.');
        }

        if ($campaign->reward_type === ReferralRewardType::Fixed) {
            return new ReferralRewardCalculationResult(
                rewardType: ReferralRewardType::Fixed,
                rewardValue: $campaign->reward_value,
                amountMinor: $campaign->reward_value,
                currencyCode: (string) $campaign->reward_currency_code,
            );
        }

        // Integer floor division — 5% of 999 minor units is 49, never
        // 49.95 rounded up. The student-favorable direction is the
        // platform-conservative one.
        return new ReferralRewardCalculationResult(
            rewardType: ReferralRewardType::Percentage,
            rewardValue: $campaign->reward_value,
            amountMinor: intdiv($lessonAmountMinor * $campaign->reward_value, 10000),
            currencyCode: $lessonCurrencyCode,
        );
    }

    public function creditReward(ReferralReward $reward): ReferralReward
    {
        return DB::transaction(function () use ($reward): ReferralReward {
            $reward = $this->locked($reward);

            // Terminal or already-credited: return as-is (idempotent).
            if ($reward->status !== ReferralRewardStatus::Eligible && $reward->status !== ReferralRewardStatus::CreditFailed) {
                return $reward;
            }

            if ($reward->credit_ready_at === null || $reward->credit_ready_at->isFuture()) {
                return $reward;
            }

            if ($reward->reward_amount_minor <= 0) {
                throw new ReferralException(sprintf('Reward #%d has a non-positive amount and can never be credited.', $reward->id));
            }

            $referrer = $reward->referrer;

            if ($referrer === null) {
                throw new ReferralException(sprintf('Reward #%d has no referrer.', $reward->id));
            }

            $wallet = $this->wallets->getOrCreateWallet($referrer, null, $referrer);

            if ($wallet->currency_code !== $reward->reward_currency_code) {
                return $this->hold($reward, 'currency_mismatch', sprintf(
                    'Reward currency %s does not match referrer wallet currency %s.',
                    $reward->reward_currency_code,
                    $wallet->currency_code,
                ));
            }

            try {
                $entry = $this->ledger->credit(
                    $wallet,
                    $reward->reward_amount_minor,
                    WalletLedgerEntryType::ReferralCredit,
                    $referrer,
                    idempotencyKey: sprintf('referral_reward:%d', $reward->id),
                    description: sprintf('Referral reward for eligible class #%d.', $reward->class_sequence),
                    sourceType: 'referral_reward',
                    sourceId: (string) $reward->id,
                    metadata: [
                        'campaign_id' => $reward->campaign_id,
                        'lesson_id' => $reward->lesson_id,
                        'attribution_id' => $reward->attribution_id,
                        'class_sequence' => $reward->class_sequence,
                    ],
                );
            } catch (WalletException $e) {
                // Closed/unusable wallet — retryable failure state; the
                // sweep will try again once the wallet is usable. A retry
                // that fails AGAIN must not re-audit or re-notify (the
                // admin bell already carries the first failure).
                $wasAlreadyFailed = $reward->status === ReferralRewardStatus::CreditFailed;

                $reward->forceFill([
                    'status' => ReferralRewardStatus::CreditFailed,
                    'hold_reason' => 'wallet_unusable',
                ])->save();

                if (! $wasAlreadyFailed) {
                    $this->auditTrail->logSystem(
                        self::LOG_NAME,
                        'reward_credit_failed',
                        sprintf('Referral reward #%d credit failed: %s', $reward->id, $e->getMessage()),
                        $reward,
                        ['wallet_id' => $wallet->id],
                    );

                    ReferralRewardCreditFailed::dispatch($reward->id, $reward->referrer_id, $reward->referred_student_id);
                }

                return $reward;
            }

            // A duplicate idempotency hit returns the existing ledger row
            // — reconcile to it; never a second credit.
            $reward->forceFill([
                'status' => ReferralRewardStatus::Credited,
                'hold_reason' => null,
                'wallet_ledger_entry_id' => $entry->id,
                'credited_at' => now(),
            ])->save();

            $this->auditTrail->logSystem(
                self::LOG_NAME,
                'reward_credited',
                sprintf('Referral reward #%d credited (%d %s minor units).', $reward->id, $reward->reward_amount_minor, $reward->reward_currency_code),
                $reward,
                ['wallet_ledger_entry_id' => $entry->id, 'campaign_id' => $reward->campaign_id],
            );

            ReferralRewardCredited::dispatch($reward->id, $reward->referrer_id, $reward->referred_student_id);

            return $reward;
        });
    }

    public function reevaluateLesson(Lesson $lesson, ?User $actor, string $reasonCode): ?ReferralReward
    {
        $reward = ReferralReward::query()->where('lesson_id', $lesson->id)->first();

        if ($reward === null) {
            return null;
        }

        return DB::transaction(function () use ($reward, $actor, $reasonCode): ReferralReward {
            $reward = $this->locked($reward);

            // Idempotent: repeated refund/override events return the
            // existing terminal result untouched.
            if ($reward->status->isTerminal()) {
                return $reward;
            }

            if ($reward->status !== ReferralRewardStatus::Credited) {
                $reward->forceFill([
                    'status' => ReferralRewardStatus::Rejected,
                    'decision_reason' => $reasonCode,
                    'decided_by' => $actor?->id,
                    'rejected_at' => now(),
                ])->save();

                $this->auditTrail->logSystem(
                    self::LOG_NAME,
                    'reward_rejected',
                    sprintf('Referral reward #%d rejected before credit (%s).', $reward->id, $reasonCode),
                    $reward,
                    ['reason_code' => $reasonCode],
                );

                ReferralRewardRejected::dispatch($reward->id, $reward->referrer_id, $reward->referred_student_id);

                return $reward;
            }

            return $this->reverseCredited($reward, $actor, $reasonCode);
        });
    }

    public function processReadyRewards(int $limit = 100): int
    {
        // Selection happens outside any transaction; every candidate is
        // re-locked and re-checked inside creditReward(), so a stale id
        // here is harmless. Stable order: oldest readiness first.
        $rewardIds = ReferralReward::query()
            ->readyForCredit(now())
            ->orderBy('credit_ready_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $credited = 0;

        foreach ($rewardIds as $rewardId) {
            $reward = ReferralReward::query()->find($rewardId);

            if ($reward === null) {
                continue;
            }

            try {
                $result = $this->creditReward($reward);

                if ($result->status === ReferralRewardStatus::Credited) {
                    $credited++;
                }
            } catch (\Throwable $e) {
                // One bad reward must never stall the batch.
                Log::warning('Referral reward sweep failed for one reward.', [
                    'reward_id' => $rewardId,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $credited;
    }

    public function approveHeldReward(ReferralReward $reward, User $admin, string $reason): ReferralReward
    {
        $this->assertAdminDecision($reward, $admin, 'ApproveReferralRewards', $reason);

        $reward = DB::transaction(function () use ($reward, $admin, $reason): ReferralReward {
            $reward = $this->locked($reward);

            if ($reward->status !== ReferralRewardStatus::Held) {
                throw new ReferralException(sprintf('Reward #%d is %s — only a held reward can be approved.', $reward->id, $reward->status->value));
            }

            $this->assertStillCreditable($reward);

            // A currency-mismatch hold may only be released once the
            // referrer's wallet currency actually matches — never by
            // overriding currency. Otherwise the admin must reject.
            $wallet = $this->wallets->getOrCreateWallet($reward->referrer, null, $reward->referrer);

            if ($wallet->currency_code !== $reward->reward_currency_code) {
                throw new ReferralException(sprintf(
                    'Reward #%d currency %s still does not match the referrer wallet currency %s — reject the reward instead; money is never converted.',
                    $reward->id,
                    $reward->reward_currency_code,
                    $wallet->currency_code,
                ));
            }

            $reward->forceFill([
                'status' => ReferralRewardStatus::Eligible,
                'hold_reason' => null,
                'decision_reason' => $reason,
                'decided_by' => $admin->id,
                'credit_ready_at' => now(),
            ])->save();

            $this->auditTrail->logOverride(
                $admin,
                self::LOG_NAME,
                'reward_approved',
                sprintf('Held referral reward #%d approved for credit.', $reward->id),
                $reason,
                $reward,
                ['campaign_id' => $reward->campaign_id, 'reward_amount_minor' => $reward->reward_amount_minor],
            );

            return $reward;
        });

        // The ONE credit path — never a direct credit from the approval.
        return $this->creditReward($reward);
    }

    public function rejectHeldReward(ReferralReward $reward, User $admin, string $reason): ReferralReward
    {
        $this->assertAdminDecision($reward, $admin, 'RejectReferralRewards', $reason);

        return DB::transaction(function () use ($reward, $admin, $reason): ReferralReward {
            $reward = $this->locked($reward);

            // Idempotent under duplicate decisions: an already-rejected
            // reward returns unchanged; any other terminal state refuses.
            if ($reward->status === ReferralRewardStatus::Rejected) {
                return $reward;
            }

            if (! in_array($reward->status, [ReferralRewardStatus::Held, ReferralRewardStatus::CreditFailed], true)) {
                throw new ReferralException(sprintf('Reward #%d is %s — only a held or credit-failed reward can be rejected.', $reward->id, $reward->status->value));
            }

            $reward->forceFill([
                'status' => ReferralRewardStatus::Rejected,
                'hold_reason' => null,
                'decision_reason' => $reason,
                'decided_by' => $admin->id,
                'rejected_at' => now(),
            ])->save();

            $this->auditTrail->logOverride(
                $admin,
                self::LOG_NAME,
                'reward_rejected_by_admin',
                sprintf('Referral reward #%d rejected by administrator.', $reward->id),
                $reason,
                $reward,
                ['campaign_id' => $reward->campaign_id],
            );

            ReferralRewardRejected::dispatch($reward->id, $reward->referrer_id, $reward->referred_student_id);

            return $reward;
        });
    }

    public function retryFailedCredit(ReferralReward $reward, User $admin, string $reason): ReferralReward
    {
        $this->assertAdminDecision($reward, $admin, 'RetryReferralRewardCredits', $reason);

        if ($reward->status !== ReferralRewardStatus::CreditFailed) {
            throw new ReferralException(sprintf('Reward #%d is %s — only a credit-failed reward can be retried.', $reward->id, $reward->status->value));
        }

        $this->auditTrail->logOverride(
            $admin,
            self::LOG_NAME,
            'reward_retry_requested',
            sprintf('Manual credit retry requested for referral reward #%d.', $reward->id),
            $reason,
            $reward,
            ['previous_status' => $reward->status->value],
        );

        // The ONE credit path: idempotent, re-locks, re-checks readiness
        // and currency; a still-failing wallet keeps the CreditFailed
        // state without re-notifying (see the wasAlreadyFailed guard).
        return $this->creditReward($reward);
    }

    public function completeRequiredReversal(ReferralReward $reward, User $admin, string $reason): ReferralReward
    {
        $this->assertAdminDecision($reward, $admin, 'ReverseReferralRewards', $reason);

        if (! $admin->can('manage', Wallet::class)) {
            throw new ReferralException('Completing a reversal requires wallet-manage authority — the ledger reversal itself is permission-checked.');
        }

        return DB::transaction(function () use ($reward, $admin, $reason): ReferralReward {
            $reward = $this->locked($reward);

            // Exactly-once under duplicate clicks: an already-reversed
            // reward is the terminal result.
            if ($reward->status === ReferralRewardStatus::Reversed) {
                return $reward;
            }

            if ($reward->status !== ReferralRewardStatus::Credited || $reward->hold_reason !== 'reversal_required') {
                throw new ReferralException(sprintf('Reward #%d is not awaiting reversal.', $reward->id));
            }

            if ($reward->reversal_ledger_entry_id !== null) {
                throw new ReferralException(sprintf('Reward #%d already carries a reversal ledger entry — data reconciliation required.', $reward->id));
            }

            $result = $this->reverseCredited($reward, $admin, 'admin_reversal: '.$reason);

            if ($result->status !== ReferralRewardStatus::Reversed) {
                // reverseCredited kept the visible exception state (e.g.
                // balance already spent) — audit THIS manual attempt so
                // the failed action is never invisible.
                $this->auditTrail->logOverride(
                    $admin,
                    self::LOG_NAME,
                    'reward_reversal_attempt_failed',
                    sprintf('Manual reversal of referral reward #%d could not complete — wallet reversal refused.', $reward->id),
                    $reason,
                    $result,
                    ['hold_reason' => $result->hold_reason],
                );
            }

            return $result;
        });
    }

    public function summaryForReferrer(User $referrer): array
    {
        $byStatus = ReferralReward::query()
            ->forReferrer($referrer->id)
            ->selectRaw('status, reward_currency_code, COUNT(*) as aggregate, SUM(reward_amount_minor) as amount')
            ->groupBy('status', 'reward_currency_code')
            ->get();

        $sumFor = fn (ReferralRewardStatus $status): array => $byStatus
            ->where('status', $status->value)
            ->mapWithKeys(fn ($row) => [$row->reward_currency_code => (int) $row->amount])
            ->all();

        $countFor = fn (ReferralRewardStatus $status): int => (int) $byStatus
            ->where('status', $status->value)
            ->sum('aggregate');

        return [
            'referred_students' => ReferralAttribution::query()->where('referrer_id', $referrer->id)->count(),
            'eligible' => $countFor(ReferralRewardStatus::Eligible) + $countFor(ReferralRewardStatus::CreditFailed),
            'held' => $countFor(ReferralRewardStatus::Held),
            'credited_by_currency' => $sumFor(ReferralRewardStatus::Credited),
            'reversed_by_currency' => $sumFor(ReferralRewardStatus::Reversed),
        ];
    }

    public function historyForReferrer(User $referrer, int $perPage = 10): LengthAwarePaginator
    {
        return ReferralReward::query()
            ->forReferrer($referrer->id)
            ->with('referredStudent:id,first_name,last_name')
            ->orderByDesc('eligible_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    private function reverseCredited(ReferralReward $reward, ?User $actor, string $reasonCode): ReferralReward
    {
        $entry = $reward->walletLedgerEntry;

        if ($entry === null) {
            throw new ReferralException(sprintf('Credited reward #%d has no ledger entry — data integrity violation.', $reward->id));
        }

        if ($actor === null || ! $actor->can('manage', Wallet::class)) {
            return $this->markReversalRequired($reward, $reasonCode, 'no_wallet_manage_actor');
        }

        try {
            $reversal = $this->ledger->reverse($entry, $actor, sprintf('Referral reward #%d invalidated: %s', $reward->id, $reasonCode));
        } catch (WalletException $e) {
            // e.g. the referrer already spent the balance — never mark
            // Reversed without a reversal entry; leave the visible
            // reconciliation state instead.
            return $this->markReversalRequired($reward, $reasonCode, 'wallet_reversal_failed: '.$e->getMessage());
        }

        $reward->forceFill([
            'status' => ReferralRewardStatus::Reversed,
            'hold_reason' => null,
            'decision_reason' => $reasonCode,
            'decided_by' => $actor->id,
            'reversal_ledger_entry_id' => $reversal->id,
            'reversed_at' => now(),
        ])->save();

        $this->auditTrail->logSystem(
            self::LOG_NAME,
            'reward_reversed',
            sprintf('Referral reward #%d reversed (%s).', $reward->id, $reasonCode),
            $reward,
            ['reversal_ledger_entry_id' => $reversal->id, 'original_ledger_entry_id' => $entry->id],
        );

        ReferralRewardReversed::dispatch($reward->id, $reward->referrer_id, $reward->referred_student_id);

        return $reward;
    }

    private function markReversalRequired(ReferralReward $reward, string $reasonCode, string $detail): ReferralReward
    {
        // Only audit the first time — repeated refund events must not
        // spam the audit trail or the admin bell.
        if ($reward->hold_reason === 'reversal_required') {
            return $reward;
        }

        $reward->forceFill([
            'hold_reason' => 'reversal_required',
            'decision_reason' => $reasonCode,
        ])->save();

        $this->auditTrail->logSystem(
            self::LOG_NAME,
            'reward_reversal_required',
            sprintf('Referral reward #%d needs manual reversal (%s).', $reward->id, $detail),
            $reward,
            ['reason_code' => $reasonCode],
        );

        return $reward;
    }

    private function hold(ReferralReward $reward, string $holdReason, string $detail): ReferralReward
    {
        $reward->forceFill([
            'status' => ReferralRewardStatus::Held,
            'hold_reason' => $holdReason,
        ])->save();

        $this->auditTrail->logSystem(
            self::LOG_NAME,
            'reward_held',
            sprintf('Referral reward #%d held (%s): %s', $reward->id, $holdReason, $detail),
            $reward,
            ['hold_reason' => $holdReason],
        );

        ReferralRewardHeld::dispatch($reward->id, $reward->referrer_id, $reward->referred_student_id);

        return $reward;
    }

    private function locked(ReferralReward $reward): ReferralReward
    {
        return ReferralReward::query()->whereKey($reward->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Shared gate for every manual reward decision: the named
     * permission, a non-empty reason, and never a self-decision — a
     * multi-role admin who is also the reward's referrer may not
     * approve, reject, retry, or reverse their own money.
     */
    private function assertAdminDecision(ReferralReward $reward, User $admin, string $permission, string $reason): void
    {
        if (! $admin->can($permission)) {
            throw new AuthorizationException;
        }

        if (trim($reason) === '') {
            throw new ReferralException('A reward decision requires a reason.');
        }

        if ($admin->id === $reward->referrer_id || $admin->id === $reward->referred_student_id) {
            throw new ReferralException('You cannot decide a referral reward you are a party to.');
        }
    }

    /**
     * Approval-time revalidation (SRS 16.30): the snapshot must still
     * describe a rewardable situation — student-only parties, an
     * eligible referrer, a still-Completed lesson with its captured
     * payment, and an intact positive snapshot.
     */
    private function assertStillCreditable(ReferralReward $reward): void
    {
        $referrer = $reward->referrer;
        $referred = $reward->referredStudent;

        if ($referrer === null || $referred === null
            || ! $referrer->hasRole('student') || ! $referred->hasRole('student')) {
            throw new ReferralException(sprintf('Reward #%d parties are no longer both students — reject it instead.', $reward->id));
        }

        // Phase 24H — GAP-013: a suspended/archived student_status no
        // longer rejects an already-held reward here. This check runs
        // at APPROVAL time for money already calculated and owed —
        // Suspended/Archived legitimately blocks becoming a NEW eligible
        // referrer (see ReferralEligibilityService, still enforced), but
        // must never forfeit an existing financial obligation, per this
        // phase's "do not block money owed to the student merely
        // because suspended/archived" requirement. User::status is
        // still checked — that's the broader account-standing question
        // (blocked/inactive), out of this phase's student-lifecycle scope.
        if ($referrer->status !== User::STATUS_ACTIVE) {
            throw new ReferralException(sprintf('Reward #%d referrer is no longer an eligible active student — reject it instead.', $reward->id));
        }

        if ($reward->reward_amount_minor <= 0 || $reward->campaign === null) {
            throw new ReferralException(sprintf('Reward #%d calculation snapshot is not creditable.', $reward->id));
        }

        if ($reward->lesson?->outcome !== LessonOutcome::Completed) {
            throw new ReferralException(sprintf('Reward #%d lesson is no longer Completed — reject it instead.', $reward->id));
        }

        $paymentStillCaptured = BookingPayment::query()
            ->where('booking_id', $reward->booking_id)
            ->where('status', BookingPaymentRecordStatus::Captured)
            ->where('amount_minor', '>', 0)
            ->exists();

        if (! $paymentStillCaptured) {
            throw new ReferralException(sprintf('Reward #%d lesson payment is no longer captured — reject it instead.', $reward->id));
        }
    }
}
