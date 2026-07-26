<?php

declare(strict_types=1);

namespace Tests\Feature\Referral\Concurrency;

use App\Models\ReferralCampaign;
use App\Models\ReferralReward;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Referral\Contracts\ReferralEligibilityServiceInterface;
use App\Referral\Contracts\ReferralRewardServiceInterface;
use App\Referral\Enums\ReferralRewardStatus;
use Spatie\Permission\Models\Role;
use Tests\Feature\Referral\Concerns\BuildsReferralRewardFixtures;

/**
 * Real multi-process races for the money invariants: unique(lesson_id)
 * makes evaluation single-winner, the locked cap
 * check + unique(attribution_id, class_sequence) bound the class cap,
 * the reward row lock + ledger idempotency key make crediting
 * exactly-once, and the ledger's Posted→Reversed transition makes
 * reversal exactly-once.
 */
class ReferralRewardConcurrencyTest extends ConcurrencyTestCase
{
    use BuildsReferralRewardFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReferralWorld();
    }

    public function test_concurrent_evaluation_of_the_same_lesson_creates_one_reward(): void
    {
        [, $referred] = $this->attributedPair();
        $this->activeCampaign();

        $lesson = $this->completedPaidLesson($referred);

        $results = $this->race([
            ['referral-evaluate-lesson', ['lesson_id' => $lesson->id]],
            ['referral-evaluate-lesson', ['lesson_id' => $lesson->id]],
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results));
        $this->assertTrue($results[1]['ok'], json_encode($results));

        $winners = array_values(array_filter([
            $results[0]['result']['reward_id'],
            $results[1]['result']['reward_id'],
        ]));

        $this->assertCount(1, $winners, json_encode($results));
        $this->assertSame(1, ReferralReward::query()->where('lesson_id', $lesson->id)->count());
    }

    public function test_final_class_cap_slot_is_awarded_exactly_once_under_race(): void
    {
        [, $referred, $attribution] = $this->attributedPair();

        // Fixtures COMMIT in this class (child processes must see them),
        // so campaigns from earlier tests survive — archive them so the
        // resolver can only pick the cap-1 campaign under test.
        ReferralCampaign::query()->update(['status' => 'archived']);
        $this->activeCampaign(['max_rewarded_classes' => 1]);

        // Two different eligible lessons race for the single slot.
        $lessonA = $this->completedPaidLesson($referred);
        $lessonB = $this->completedPaidLesson($referred);

        $results = $this->race([
            ['referral-evaluate-lesson', ['lesson_id' => $lessonA->id]],
            ['referral-evaluate-lesson', ['lesson_id' => $lessonB->id]],
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results));
        $this->assertTrue($results[1]['ok'], json_encode($results));

        $winners = array_values(array_filter([
            $results[0]['result']['reward_id'],
            $results[1]['result']['reward_id'],
        ]));

        $this->assertCount(1, $winners, json_encode($results));
        $this->assertSame(1, ReferralReward::query()->where('attribution_id', $attribution->id)->count());
    }

    public function test_two_workers_crediting_the_same_reward_post_one_ledger_entry(): void
    {
        [, $referred] = $this->attributedPair();
        ReferralCampaign::query()->update(['status' => 'archived']);
        $this->activeCampaign();

        $reward = app(ReferralEligibilityServiceInterface::class)
            ->evaluateCompletedLesson($this->completedPaidLesson($referred));

        $results = $this->race([
            ['referral-credit-reward', ['reward_id' => $reward->id]],
            ['referral-credit-reward', ['reward_id' => $reward->id]],
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results));
        $this->assertTrue($results[1]['ok'], json_encode($results));
        $this->assertSame('credited', $results[0]['result']['status'], json_encode($results));
        $this->assertSame('credited', $results[1]['result']['status'], json_encode($results));

        $this->assertSame(1, WalletLedgerEntry::query()->where('entry_type', 'referral_credit')->count());
        $this->assertSame(2500, Wallet::query()->where('user_id', $reward->referrer_id)->sole()->balance_minor);
    }

    public function test_concurrent_reversal_produces_exactly_one_reversal_entry(): void
    {
        [, $referred] = $this->attributedPair();
        ReferralCampaign::query()->update(['status' => 'archived']);
        $this->activeCampaign();

        $lesson = $this->completedPaidLesson($referred);
        $reward = app(ReferralEligibilityServiceInterface::class)->evaluateCompletedLesson($lesson);
        app(ReferralRewardServiceInterface::class)->creditReward($reward);

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');

        $results = $this->race([
            ['referral-reverse-lesson', ['lesson_id' => $lesson->id, 'actor_id' => $admin->id]],
            ['referral-reverse-lesson', ['lesson_id' => $lesson->id, 'actor_id' => $admin->id]],
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results));
        $this->assertTrue($results[1]['ok'], json_encode($results));

        $reward->refresh();
        $this->assertSame(ReferralRewardStatus::Reversed, $reward->status);
        $this->assertSame(1, WalletLedgerEntry::query()->where('direction', 'debit')->count());
        $this->assertSame(0, Wallet::query()->where('user_id', $reward->referrer_id)->sole()->balance_minor);
    }
}
