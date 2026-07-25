<?php

declare(strict_types=1);

namespace App\Compliance\Rules;

use App\Compliance\DTOs\SuspiciousActivitySignal;
use App\Compliance\Enums\SuspiciousActivityFlagSeverity;
use App\Compliance\Enums\SuspiciousActivityRuleCode;
use App\Compliance\Support\ComplianceThresholdSnapshot;
use App\Models\ReferralReward;
use App\Settings\ComplianceMonitoringSettings;
use Illuminate\Support\Facades\Date;

/**
 * SRS §9.14/§24.26 — a referrer repeatedly having rewards placed on
 * fraud hold within a rolling window. `referral_rewards` has no
 * dedicated `held_at` timestamp, so `updated_at` is used as the
 * window bound for rows currently in the Held status — the same
 * column ReferralRewardService already updates on every status
 * transition.
 */
final class RepeatedReferralFraudHoldsRule
{
    public function __construct(
        private readonly ComplianceMonitoringSettings $settings,
    ) {}

    public function evaluate(int $referrerId): ?SuspiciousActivitySignal
    {
        if (! $this->settings->repeated_referral_fraud_holds_enabled) {
            return null;
        }

        $windowDays = $this->settings->repeated_referral_fraud_holds_window_days;
        $cutoff = Date::now()->subDays($windowDays);

        $count = ReferralReward::query()
            ->where('referrer_id', $referrerId)
            ->where('status', 'held')
            ->where('updated_at', '>=', $cutoff)
            ->count();

        if ($count < $this->settings->repeated_referral_fraud_holds_threshold) {
            return null;
        }

        return new SuspiciousActivitySignal(
            ruleCode: SuspiciousActivityRuleCode::RepeatedReferralFraudHolds,
            ruleVersion: 1,
            subjectId: $referrerId,
            actorId: null,
            occurredAt: Date::now(),
            severity: SuspiciousActivityFlagSeverity::from($this->settings->repeated_referral_fraud_holds_severity),
            evidence: [
                'held_reward_count' => $count,
                'window_days' => $windowDays,
                'threshold' => $this->settings->repeated_referral_fraud_holds_threshold,
            ],
            thresholdSnapshot: ComplianceThresholdSnapshot::capture(SuspiciousActivityRuleCode::RepeatedReferralFraudHolds, $this->settings),
            cooldownMinutes: $this->settings->repeated_referral_fraud_holds_cooldown_minutes,
        );
    }
}
