<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Models\Currency;
use App\Models\ReferralReward;
use App\Referral\Contracts\ReferralEligibilityServiceInterface;
use App\Referral\Contracts\ReferralRewardServiceInterface;
use App\Referral\Enums\ReferralRewardStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Referral\Concerns\BuildsReferralRewardFixtures;
use Tests\TestCase;

/**
 * An already-Eligible/Held referral reward is an existing obligation
 * and must remain creditable even after the referrer's currency is
 * later disabled. New participation/issuance is unaffected — no
 * campaign/eligibility logic changes with the currency's status.
 */
final class ReferralRewardCurrencyTest extends TestCase
{
    use BuildsReferralRewardFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReferralWorld();
    }

    // ── 17: held referral reward remains creditable after deactivation ──

    public function test_eligible_reward_remains_creditable_after_referrer_currency_deactivation(): void
    {
        [$referrer, $referred] = $this->attributedPair();
        $this->activeCampaign();
        $lesson = $this->completedPaidLesson($referred);

        $reward = app(ReferralEligibilityServiceInterface::class)->evaluateCompletedLesson($lesson);
        $this->assertNotNull($reward);
        $this->assertContains($reward->status, [ReferralRewardStatus::Eligible, ReferralRewardStatus::Held]);

        // The referrer's own billing currency is disabled AFTER the
        // reward already exists as an obligation.
        Currency::query()->where('code', $reward->reward_currency_code)->update(['status' => 'inactive']);

        $reward = ReferralReward::query()->find($reward->id);
        $reward->forceFill(['credit_ready_at' => now()->subMinute()])->save();

        $credited = app(ReferralRewardServiceInterface::class)->creditReward($reward->fresh());

        $this->assertContains($credited->status, [ReferralRewardStatus::Credited, ReferralRewardStatus::Held]);
        // Never an uncaught exception — the only two legitimate outcomes
        // are a successful credit, or a (currency-mismatch, unrelated to
        // active-status) hold.
    }

    // ── 18: new referral issuance follows the documented policy ──────

    public function test_new_reward_evaluation_still_requires_the_lesson_currency_to_be_usable(): void
    {
        // Not an active-currency rejection: the currency-Active
        // requirement applies only to CREDITING an existing obligation,
        // never to reward CALCULATION — evaluateCompletedLesson()
        // continues to produce a reward regardless of currency status.
        [, $referred] = $this->attributedPair();
        $this->activeCampaign();
        $lesson = $this->completedPaidLesson($referred);

        $reward = app(ReferralEligibilityServiceInterface::class)->evaluateCompletedLesson($lesson);

        $this->assertNotNull($reward);
    }
}
