<?php

declare(strict_types=1);

namespace App\Referral\Contracts;

use App\Models\Lesson;
use App\Models\ReferralCampaign;
use App\Models\ReferralReward;
use App\Models\User;
use App\Referral\DTOs\ReferralRewardCalculationResult;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ReferralRewardServiceInterface
{
    /**
     * The single reward-calculation owner. Integer arithmetic only:
     * percentage = floor(lesson_amount_minor × basis_points / 10000) in
     * the lesson currency; fixed = the campaign's minor units in the
     * campaign currency. Never converts currency.
     */
    public function calculate(ReferralCampaign $campaign, int $lessonAmountMinor, string $lessonCurrencyCode): ReferralRewardCalculationResult;

    /**
     * The ONLY wallet-credit path for referral rewards — immediate
     * processing and the scheduled sweep both call this. Locks the
     * reward, rechecks status/readiness, resolves the referrer's
     * default-currency wallet, verifies exact currency match (mismatch
     * → Held), and credits through WalletLedgerService with the
     * deterministic idempotency key `referral_reward:{id}`. An unusable
     * wallet moves the reward to CreditFailed (retryable); a duplicate
     * idempotency hit reconciles to the existing ledger row.
     */
    public function creditReward(ReferralReward $reward): ReferralReward;

    /**
     * The single reversal/invalidation path (outcome override, refund).
     * Before credit → Rejected (no wallet movement). After credit →
     * WalletLedgerService::reverse() when $actor may manage wallets;
     * otherwise the reward keeps status Credited with the visible
     * `reversal_required` reconciliation reason for Phase 19E — it is
     * never falsely marked Reversed. Idempotent under repeated events.
     */
    public function reevaluateLesson(Lesson $lesson, ?User $actor, string $reasonCode): ?ReferralReward;

    /**
     * The scheduled sweep: credits Eligible rewards whose
     * credit_ready_at has passed, in stable order, bounded to one batch,
     * with per-reward failure isolation. Held, future-ready, terminal
     * and credited rewards are never selected. Returns the number
     * successfully credited. Safe to re-run at any frequency —
     * creditReward()'s lock/recheck plus the ledger idempotency key
     * make repeats no-ops.
     */
    public function processReadyRewards(int $limit = 100): int;

    /**
     * Phase 19E admin decisions. Each requires its named permission, a
     * non-empty reason, forbids self-decision, locks the reward, and
     * re-checks state — duplicate decisions return the terminal result,
     * never a second ledger entry.
     */
    public function approveHeldReward(ReferralReward $reward, User $admin, string $reason): ReferralReward;

    public function rejectHeldReward(ReferralReward $reward, User $admin, string $reason): ReferralReward;

    public function retryFailedCredit(ReferralReward $reward, User $admin, string $reason): ReferralReward;

    public function completeRequiredReversal(ReferralReward $reward, User $admin, string $reason): ReferralReward;

    /**
     * Currency-separated, source-backed totals for the referrer's
     * Refer a Friend page. Never mixes currencies.
     *
     * @return array{referred_students: int, eligible: int, held: int, credited_by_currency: array<string, int>, reversed_by_currency: array<string, int>}
     */
    public function summaryForReferrer(User $referrer): array;

    public function historyForReferrer(User $referrer, int $perPage = 10): LengthAwarePaginator;
}
