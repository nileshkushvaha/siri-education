<?php

declare(strict_types=1);

namespace App\Listeners\Compliance;

use App\Compliance\Rules\RepeatedReferralFraudHoldsRule;
use App\Compliance\Services\ComplianceMonitoringService;
use App\Referral\Events\ReferralRewardHeld;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Thin trigger — all detection logic lives in RepeatedReferralFraudHoldsRule. */
final class EvaluateRepeatedReferralFraudHoldsOnReferralRewardHeld implements ShouldQueue
{
    public string $queue = 'compliance';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly RepeatedReferralFraudHoldsRule $rule,
        private readonly ComplianceMonitoringService $compliance,
    ) {}

    public function handle(ReferralRewardHeld $event): void
    {
        $signal = $this->rule->evaluate($event->referrerId);

        if ($signal !== null) {
            $this->compliance->record($signal);
        }
    }
}
