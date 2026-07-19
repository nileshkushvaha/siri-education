<?php

declare(strict_types=1);

namespace App\Referral\Services;

use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Enums\StudentStatus;
use App\Lessons\Enums\LessonOutcome;
use App\Models\BookingPayment;
use App\Models\Lesson;
use App\Models\ReferralAttribution;
use App\Models\ReferralCampaign;
use App\Models\ReferralReward;
use App\Models\User;
use App\Referral\Contracts\ReferralCampaignServiceInterface;
use App\Referral\Contracts\ReferralEligibilityServiceInterface;
use App\Referral\Contracts\ReferralRewardServiceInterface;
use App\Referral\Enums\ReferralRewardStatus;
use App\Referral\Enums\ReferralRewardTiming;
use App\Referral\Events\ReferralRewardEligible;
use App\Referral\Events\ReferralRewardHeld;
use App\Referral\Events\ReferralRewardRejected;
use App\Services\AuditTrailService;
use App\Settings\FeatureSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SRS 16.11 eligibility, evaluated once per finalized-Completed lesson.
 *
 * Decisions this service pins down (documented + tested):
 *  - The campaign window is matched at the lesson's outcome_finalized_at
 *    instant (stable under queue retries and duplicate delivery); the
 *    campaign's Active status is required at evaluation time — a paused
 *    or completed campaign generates nothing (SRS 16.22).
 *  - The minimum-lesson threshold counts only qualifying lessons
 *    (Completed outcome, paid booking type, captured positive payment)
 *    finalized INSIDE the campaign window — lessons completed before the
 *    campaign started do not contribute (SRS is silent; the conservative
 *    reading was chosen).
 *  - The class cap counts every non-Rejected reward for the attribution
 *    + campaign; a Reversed reward still consumes its slot, so a
 *    refund-and-rebook cycle can never farm extra slots.
 *  - A zero-minor-unit percentage result creates a terminal Rejected row
 *    (reason zero_reward_amount) — the lesson is consumed, nothing is
 *    ever credited, and no later retry can flip it.
 *
 * unique(lesson_id) and unique(attribution_id, class_sequence) remain
 * the final concurrency guards; the application-level counts are only
 * the polite layer in front of them.
 */
final class ReferralEligibilityService implements ReferralEligibilityServiceInterface
{
    public function __construct(
        private readonly FeatureSettings $features,
        private readonly ReferralCampaignServiceInterface $campaigns,
        private readonly ReferralRewardServiceInterface $rewards,
        private readonly AuditTrailService $auditTrail,
    ) {}

    public function evaluateCompletedLesson(Lesson $lesson): ?ReferralReward
    {
        if (! $this->features->referral_enabled) {
            return null;
        }

        if ($lesson->outcome !== LessonOutcome::Completed || $lesson->outcome_finalized_at === null) {
            return null;
        }

        // Cheap pre-check; re-checked under lock before insert.
        if (ReferralReward::query()->where('lesson_id', $lesson->id)->exists()) {
            return null;
        }

        $attribution = ReferralAttribution::query()
            ->where('referred_student_id', $lesson->student_id)
            ->first();

        if ($attribution === null) {
            return null;
        }

        $referrer = $attribution->referrer;
        $referred = $attribution->referredStudent;

        if ($referrer === null || $referred === null || ! $this->participantsAreEligibleStudents($referrer, $referred)) {
            return null;
        }

        $booking = $lesson->booking;

        if ($booking === null || ! ($booking->type?->is_paid ?? false)) {
            return null;
        }

        $payment = $this->capturedPayment($booking->id);

        if ($payment === null || $payment->amount_minor <= 0) {
            return null;
        }

        $campaign = $this->campaigns->activeCampaignFor(
            $lesson->outcome_finalized_at,
            $referred->profile?->country_id,
        );

        if ($campaign === null) {
            return null;
        }

        $calculation = $this->rewards->calculate($campaign, $payment->amount_minor, $payment->currency_code);

        try {
            $reward = DB::transaction(function () use ($lesson, $attribution, $campaign, $payment, $calculation, $referred): ?ReferralReward {
                // Canonical lock: the attribution row serializes every
                // reward decision for this referred student — including
                // Phase 19E attribution corrections. The referrer is
                // re-derived from the LOCKED row so a correction that
                // committed after the pre-checks can never leave a reward
                // pointing at the stale referrer.
                $attribution = ReferralAttribution::query()->whereKey($attribution->id)->lockForUpdate()->firstOrFail();
                $referrer = $attribution->referrer;

                if ($referrer === null) {
                    return null;
                }

                if (ReferralReward::query()->where('lesson_id', $lesson->id)->exists()) {
                    return null;
                }

                if ($this->qualifyingLessonCount($referred->id, $campaign) < $campaign->min_completed_paid_lessons) {
                    return null;
                }

                $slotsUsed = ReferralReward::query()
                    ->where('attribution_id', $attribution->id)
                    ->where('campaign_id', $campaign->id)
                    ->where('status', '!=', ReferralRewardStatus::Rejected)
                    ->count();

                if ($slotsUsed >= $campaign->max_rewarded_classes) {
                    return null;
                }

                $sequence = (int) ReferralReward::query()
                    ->where('attribution_id', $attribution->id)
                    ->max('class_sequence') + 1;

                [$status, $holdReason, $decisionReason, $creditReadyAt, $rejectedAt] = $this->initialState($campaign, $calculation->amountMinor);

                $reward = ReferralReward::query()->create([
                    'attribution_id' => $attribution->id,
                    'campaign_id' => $campaign->id,
                    'referrer_id' => $referrer->id,
                    'referred_student_id' => $referred->id,
                    'lesson_id' => $lesson->id,
                    'booking_id' => $payment->booking_id,
                    'class_sequence' => $sequence,
                    'lesson_amount_minor' => $payment->amount_minor,
                    'lesson_currency_code' => $payment->currency_code,
                    'reward_type' => $calculation->rewardType,
                    'reward_value' => $calculation->rewardValue,
                    'reward_amount_minor' => $calculation->amountMinor,
                    'reward_currency_code' => $calculation->currencyCode,
                    'status' => $status,
                    'hold_reason' => $holdReason,
                    'decision_reason' => $decisionReason,
                    'eligible_at' => now(),
                    'credit_ready_at' => $creditReadyAt,
                    'rejected_at' => $rejectedAt,
                ]);

                // Held creations audit as reward_held so the admin bell
                // (NotificationMapper) surfaces the fraud-review queue.
                $this->auditTrail->logSystem(
                    'referral_rewards',
                    $status === ReferralRewardStatus::Held ? 'reward_held' : 'reward_evaluated',
                    sprintf('Referral reward #%d (%s) created for lesson %s.', $reward->id, $status->value, $lesson->id),
                    $reward,
                    [
                        'campaign_id' => $campaign->id,
                        'class_sequence' => $sequence,
                        'reward_amount_minor' => $calculation->amountMinor,
                        'reward_currency_code' => $calculation->currencyCode,
                    ],
                );

                match ($status) {
                    ReferralRewardStatus::Eligible => ReferralRewardEligible::dispatch($reward->id, $referrer->id, $referred->id),
                    ReferralRewardStatus::Held => ReferralRewardHeld::dispatch($reward->id, $referrer->id, $referred->id),
                    default => ReferralRewardRejected::dispatch($reward->id, $referrer->id, $referred->id),
                };

                return $reward;
            });
        } catch (QueryException) {
            // Lost the unique-index race to a concurrent evaluation —
            // the winner's reward stands.
            return null;
        }

        return $reward;
    }

    private function participantsAreEligibleStudents(User $referrer, User $referred): bool
    {
        if (! $referrer->hasRole('student') || ! $referred->hasRole('student')) {
            return false;
        }

        if ($referrer->status !== User::STATUS_ACTIVE) {
            return false;
        }

        // Phase 24H.2 — GAP-013: aligned with the strict lifecycle rule
        // — the referrer must be exactly Active (Registered/null no
        // longer qualify), matching ReferralAttributionService. This
        // gates EARNING NEW rewards only; already-earned/held rewards
        // remain creditable (see ReferralRewardService, Phase 24H fix).
        return $referrer->profile?->student_status === StudentStatus::Active;
    }

    private function capturedPayment(string $bookingId): ?BookingPayment
    {
        return BookingPayment::query()
            ->where('booking_id', $bookingId)
            ->where('status', BookingPaymentRecordStatus::Captured)
            ->orderByDesc('paid_at')
            ->first();
    }

    /** Qualifying = Completed outcome + paid type + captured positive payment, finalized inside the campaign window. */
    private function qualifyingLessonCount(int $referredStudentId, ReferralCampaign $campaign): int
    {
        return Lesson::query()
            ->where('student_id', $referredStudentId)
            ->where('outcome', LessonOutcome::Completed)
            ->where('outcome_finalized_at', '>=', $campaign->starts_at)
            ->where('outcome_finalized_at', '<', $campaign->ends_at)
            ->whereHas('booking', function ($query): void {
                $query->whereHas('type', fn ($type) => $type->where('is_paid', true))
                    ->whereHas('payments', function ($payment): void {
                        $payment->where('status', BookingPaymentRecordStatus::Captured)
                            ->where('amount_minor', '>', 0);
                    });
            })
            ->count();
    }

    /** @return array{0: ReferralRewardStatus, 1: ?string, 2: ?string, 3: ?Carbon, 4: ?Carbon} */
    private function initialState(ReferralCampaign $campaign, int $amountMinor): array
    {
        // A zero-minor-unit calculation is terminal: never creditable.
        if ($amountMinor === 0) {
            return [ReferralRewardStatus::Rejected, null, 'zero_reward_amount', null, now()];
        }

        $creditReadyAt = $campaign->reward_timing === ReferralRewardTiming::AfterHoldDays
            ? now()->addDays($campaign->hold_days)
            : now();

        if ($campaign->requires_fraud_review) {
            return [ReferralRewardStatus::Held, 'fraud_review', null, $creditReadyAt, null];
        }

        return [ReferralRewardStatus::Eligible, null, null, $creditReadyAt, null];
    }
}
