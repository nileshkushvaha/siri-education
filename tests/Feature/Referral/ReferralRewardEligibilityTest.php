<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Enums\StudentStatus;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Events\LessonOutcomeFinalized;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\ReferralCampaign;
use App\Models\ReferralReward;
use App\Models\WalletLedgerEntry;
use App\Referral\Contracts\ReferralEligibilityServiceInterface;
use App\Referral\Contracts\ReferralRewardServiceInterface;
use App\Referral\Enums\ReferralRewardStatus;
use App\Referral\Enums\ReferralRewardTiming;
use App\Referral\Enums\ReferralRewardType;
use App\Settings\FeatureSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Referral\Concerns\BuildsReferralRewardFixtures;
use Tests\TestCase;

class ReferralRewardEligibilityTest extends TestCase
{
    use BuildsReferralRewardFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReferralWorld();
    }

    private function eligibility(): ReferralEligibilityServiceInterface
    {
        return app(ReferralEligibilityServiceInterface::class);
    }

    public function test_completed_paid_lesson_creates_an_eligible_percentage_reward(): void
    {
        [$referrer, $referred] = $this->attributedPair();
        $this->activeCampaign();

        $lesson = $this->completedPaidLesson($referred, amountMinor: 50000);

        $reward = $this->eligibility()->evaluateCompletedLesson($lesson);

        $this->assertNotNull($reward);
        $this->assertSame(ReferralRewardStatus::Eligible, $reward->status);
        $this->assertSame($referrer->id, $reward->referrer_id);
        $this->assertSame(1, $reward->class_sequence);
        // floor(50000 × 500 / 10000) = 2500 minor units, lesson currency.
        $this->assertSame(2500, $reward->reward_amount_minor);
        $this->assertSame('INR', $reward->reward_currency_code);
        $this->assertSame(50000, $reward->lesson_amount_minor);
        $this->assertSame(500, $reward->reward_value);
        $this->assertSame(ReferralRewardType::Percentage, $reward->reward_type);
        $this->assertNotNull($reward->credit_ready_at);
    }

    public function test_percentage_rounding_floors_and_zero_result_is_terminal_rejected(): void
    {
        [, $referred] = $this->attributedPair();
        $this->activeCampaign(['reward_value' => 1]); // 0.01%

        // floor(199 × 1 / 10000) = 0 → terminal Rejected, never credited.
        $lesson = $this->completedPaidLesson($referred, amountMinor: 199);

        $reward = $this->eligibility()->evaluateCompletedLesson($lesson);

        $this->assertNotNull($reward);
        $this->assertSame(ReferralRewardStatus::Rejected, $reward->status);
        $this->assertSame(0, $reward->reward_amount_minor);
        $this->assertSame('zero_reward_amount', $reward->decision_reason);
        $this->assertNotNull($reward->rejected_at);

        // Re-evaluation cannot flip it.
        $this->assertNull($this->eligibility()->evaluateCompletedLesson($lesson));
        $this->assertSame(1, ReferralReward::query()->count());
    }

    public function test_fixed_campaign_rewards_campaign_currency_and_amount(): void
    {
        [, $referred] = $this->attributedPair();
        $this->activeCampaign([
            'reward_type' => ReferralRewardType::Fixed,
            'reward_value' => 10000,
            'reward_currency_code' => 'INR',
        ]);

        $reward = $this->eligibility()->evaluateCompletedLesson($this->completedPaidLesson($referred));

        $this->assertSame(10000, $reward->reward_amount_minor);
        $this->assertSame('INR', $reward->reward_currency_code);
        $this->assertSame(ReferralRewardType::Fixed, $reward->reward_type);
    }

    public function test_snapshot_survives_later_campaign_rule_knowledge(): void
    {
        [, $referred] = $this->attributedPair();
        $campaign = $this->activeCampaign();

        $reward = $this->eligibility()->evaluateCompletedLesson($this->completedPaidLesson($referred));

        // Campaign rules are frozen by the immutability guard, but even a
        // raw DB change must not alter the reward's stored calculation.
        $campaign->forceFill(['reward_value' => 9999])->save();

        $reward->refresh();
        $this->assertSame(500, $reward->reward_value);
        $this->assertSame(2500, $reward->reward_amount_minor);
    }

    public function test_free_demo_and_unpaid_bookings_are_excluded(): void
    {
        [, $referred] = $this->attributedPair();
        $this->activeCampaign();

        $freeType = BookingType::factory()->create(['is_paid' => false]);
        $lesson = $this->completedPaidLesson($referred, type: $freeType);

        $this->assertNull($this->eligibility()->evaluateCompletedLesson($lesson));
        $this->assertSame(0, ReferralReward::query()->count());
    }

    public function test_uncaptured_payment_is_excluded(): void
    {
        [, $referred] = $this->attributedPair();
        $this->activeCampaign();

        $lesson = $this->completedPaidLesson($referred);
        $lesson->booking->payments()->update(['status' => 'failed']);

        $this->assertNull($this->eligibility()->evaluateCompletedLesson($lesson));
    }

    public function test_non_completed_outcomes_are_excluded(): void
    {
        [, $referred] = $this->attributedPair();
        $this->activeCampaign();

        foreach ([LessonOutcome::StudentNoShow, LessonOutcome::TechnicalIssue, LessonOutcome::Cancelled] as $outcome) {
            $lesson = $this->completedPaidLesson($referred);
            $lesson->forceFill(['outcome' => $outcome])->saveQuietly();

            $this->assertNull($this->eligibility()->evaluateCompletedLesson($lesson->refresh()), "Outcome {$outcome->value} must never reward.");
        }
    }

    public function test_missing_attribution_disabled_feature_and_no_campaign_are_excluded(): void
    {
        // No attribution.
        $stranger = $this->activeStudent();
        $this->activeCampaign();
        $this->assertNull($this->eligibility()->evaluateCompletedLesson($this->completedPaidLesson($stranger)));

        // Feature disabled.
        [, $referred] = $this->attributedPair();
        $features = app(FeatureSettings::class);
        $features->referral_enabled = false;
        $features->save();
        $this->assertNull($this->eligibility()->evaluateCompletedLesson($this->completedPaidLesson($referred)));

        // Feature on, but no active campaign.
        $features->referral_enabled = true;
        $features->save();
        ReferralCampaign::query()->update(['status' => 'paused']);
        $this->assertNull($this->eligibility()->evaluateCompletedLesson($this->completedPaidLesson($referred)));

        $this->assertSame(0, ReferralReward::query()->count());
    }

    public function test_country_scope_is_enforced(): void
    {
        $india = Country::query()->create(['name' => 'India', 'iso2' => 'IN']);
        $uk = Country::query()->create(['name' => 'United Kingdom', 'iso2' => 'GB']);

        [, $referred] = $this->attributedPair();
        $referred->profile?->update(['country_id' => $uk->id]);

        $campaign = $this->activeCampaign();
        $campaign->eligibleCountries()->sync([$india->id]);

        $this->assertNull($this->eligibility()->evaluateCompletedLesson($this->completedPaidLesson($referred)));

        $campaign->eligibleCountries()->sync([$india->id, $uk->id]);

        $this->assertNotNull($this->eligibility()->evaluateCompletedLesson($this->completedPaidLesson($referred)));
    }

    public function test_minimum_lesson_threshold_counts_only_lessons_inside_the_campaign_window(): void
    {
        [, $referred] = $this->attributedPair();
        $this->activeCampaign([
            'min_completed_paid_lessons' => 2,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
        ]);

        // A lesson completed BEFORE the campaign window contributes nothing.
        $this->completedPaidLesson($referred, finalizedAt: now()->subDays(10));

        $first = $this->completedPaidLesson($referred);
        $this->assertNull($this->eligibility()->evaluateCompletedLesson($first), 'One in-window lesson must not meet a threshold of two.');

        $second = $this->completedPaidLesson($referred);
        $reward = $this->eligibility()->evaluateCompletedLesson($second);

        $this->assertNotNull($reward, 'The second in-window lesson meets the threshold.');
        $this->assertSame(1, ReferralReward::query()->count());
    }

    public function test_maximum_rewarded_classes_is_enforced(): void
    {
        [, $referred] = $this->attributedPair();
        $this->activeCampaign(['max_rewarded_classes' => 2]);

        $this->assertNotNull($this->eligibility()->evaluateCompletedLesson($this->completedPaidLesson($referred)));
        $this->assertNotNull($this->eligibility()->evaluateCompletedLesson($this->completedPaidLesson($referred)));

        // Third eligible lesson: cap reached, no reward.
        $this->assertNull($this->eligibility()->evaluateCompletedLesson($this->completedPaidLesson($referred)));
        $this->assertSame(2, ReferralReward::query()->count());
        $this->assertSame([1, 2], ReferralReward::query()->orderBy('class_sequence')->pluck('class_sequence')->all());
    }

    public function test_fraud_review_campaign_creates_held_reward(): void
    {
        [, $referred] = $this->attributedPair();
        $this->activeCampaign(['requires_fraud_review' => true]);

        $reward = $this->eligibility()->evaluateCompletedLesson($this->completedPaidLesson($referred));

        $this->assertSame(ReferralRewardStatus::Held, $reward->status);
        $this->assertSame('fraud_review', $reward->hold_reason);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'referral_rewards', 'event' => 'reward_held']);
    }

    public function test_hold_days_campaign_defers_credit_readiness(): void
    {
        [, $referred] = $this->attributedPair();
        $this->activeCampaign([
            'reward_timing' => ReferralRewardTiming::AfterHoldDays,
            'hold_days' => 7,
        ]);

        $reward = $this->eligibility()->evaluateCompletedLesson($this->completedPaidLesson($referred));

        $this->assertSame(ReferralRewardStatus::Eligible, $reward->status);
        $this->assertTrue($reward->credit_ready_at->greaterThan(now()->addDays(6)));
    }

    public function test_duplicate_finalized_event_creates_exactly_one_reward_and_listener_credits_immediately(): void
    {
        [$referrer, $referred] = $this->attributedPair();
        $this->activeCampaign();

        $lesson = $this->completedPaidLesson($referred);

        // The real wiring: dispatching the event runs the queued listener
        // synchronously in tests; a duplicate delivery is a no-op.
        LessonOutcomeFinalized::dispatch($lesson, LessonOutcome::Completed, 'test');
        LessonOutcomeFinalized::dispatch($lesson, LessonOutcome::Completed, 'test');

        $reward = ReferralReward::query()->sole();

        // Immediate timing: the listener credited through creditReward().
        $this->assertSame(ReferralRewardStatus::Credited, $reward->status);
        $this->assertNotNull($reward->wallet_ledger_entry_id);
        $this->assertSame(1, WalletLedgerEntry::query()->where('entry_type', 'referral_credit')->count());
    }

    public function test_suspended_referrer_is_excluded_at_evaluation_time(): void
    {
        [$referrer, $referred] = $this->attributedPair();
        $this->activeCampaign();

        $referrer->profile?->update(['student_status' => StudentStatus::Suspended]);

        $this->assertNull($this->eligibility()->evaluateCompletedLesson($this->completedPaidLesson($referred)));
    }

    public function test_calculation_service_is_pure_integer_math(): void
    {
        $campaign = $this->activeCampaign(['reward_value' => 333]); // 3.33%

        $result = app(ReferralRewardServiceInterface::class)->calculate($campaign, 9999, 'USD');

        // floor(9999 × 333 / 10000) = floor(332.9667) = 332
        $this->assertSame(332, $result->amountMinor);
        $this->assertSame('USD', $result->currencyCode);
    }
}
