<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Events\LessonOutcomeOverridden;
use App\Models\Activity;
use App\Models\Lesson;
use App\Models\ReferralReward;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Referral\Contracts\ReferralEligibilityServiceInterface;
use App\Referral\Contracts\ReferralRewardServiceInterface;
use App\Referral\Enums\ReferralRewardStatus;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Enums\WalletLedgerStatus;
use App\Wallet\Services\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Feature\Referral\Concerns\BuildsReferralRewardFixtures;
use Tests\TestCase;

class ReferralRewardReversalTest extends TestCase
{
    use BuildsReferralRewardFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReferralWorld();
    }

    private function rewards(): ReferralRewardServiceInterface
    {
        return app(ReferralRewardServiceInterface::class);
    }

    private function walletAdmin(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');

        return $admin;
    }

    /** @return array{0: ReferralReward, 1: Lesson} */
    private function rewardForLesson(bool $credited): array
    {
        [, $referred] = $this->attributedPair();
        $this->activeCampaign();

        $lesson = $this->completedPaidLesson($referred);
        $reward = app(ReferralEligibilityServiceInterface::class)->evaluateCompletedLesson($lesson);

        if ($credited) {
            $reward = $this->rewards()->creditReward($reward);
        }

        return [$reward, $lesson];
    }

    public function test_invalidation_before_credit_rejects_without_wallet_movement(): void
    {
        [$reward, $lesson] = $this->rewardForLesson(credited: false);

        $result = $this->rewards()->reevaluateLesson($lesson, null, 'lesson_refunded');

        $this->assertSame(ReferralRewardStatus::Rejected, $result->status);
        $this->assertSame('lesson_refunded', $result->decision_reason);
        $this->assertNotNull($result->rejected_at);
        $this->assertSame(0, WalletLedgerEntry::query()->count());
    }

    public function test_invalidation_after_credit_reverses_with_linked_ledger_entries(): void
    {
        [$reward, $lesson] = $this->rewardForLesson(credited: true);
        $admin = $this->walletAdmin();

        $result = $this->rewards()->reevaluateLesson($lesson, $admin, 'lesson_refunded');

        $this->assertSame(ReferralRewardStatus::Reversed, $result->status);
        $this->assertNotNull($result->reversal_ledger_entry_id);
        $this->assertNotNull($result->reversed_at);
        $this->assertSame($admin->id, $result->decided_by);

        // Original flipped to Reversed; the offsetting entry restores balance.
        $original = WalletLedgerEntry::query()->findOrFail($result->wallet_ledger_entry_id);
        $this->assertSame(WalletLedgerStatus::Reversed, $original->status);

        $reversal = WalletLedgerEntry::query()->findOrFail($result->reversal_ledger_entry_id);
        $this->assertSame($result->wallet_ledger_entry_id, $reversal->metadata['reversal_of']);

        $wallet = Wallet::query()->where('user_id', $result->referrer_id)->sole();
        $this->assertSame(0, $wallet->balance_minor);
    }

    public function test_repeated_invalidation_events_produce_exactly_one_reversal(): void
    {
        [$reward, $lesson] = $this->rewardForLesson(credited: true);
        $admin = $this->walletAdmin();

        $this->rewards()->reevaluateLesson($lesson, $admin, 'lesson_refunded');
        $again = $this->rewards()->reevaluateLesson($lesson, $admin, 'lesson_refunded');

        $this->assertSame(ReferralRewardStatus::Reversed, $again->status);
        $this->assertSame(1, WalletLedgerEntry::query()->where('direction', 'debit')->count());

        $wallet = Wallet::query()->where('user_id', $reward->referrer_id)->sole();
        $this->assertSame(0, $wallet->balance_minor);
    }

    public function test_automated_invalidation_without_capable_actor_parks_reversal_required(): void
    {
        [$reward, $lesson] = $this->rewardForLesson(credited: true);

        $result = $this->rewards()->reevaluateLesson($lesson, null, 'lesson_refunded');

        // Never falsely Reversed — visibly parked for Phase 19E instead.
        $this->assertSame(ReferralRewardStatus::Credited, $result->status);
        $this->assertSame('reversal_required', $result->hold_reason);
        $this->assertNull($result->reversal_ledger_entry_id);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'referral_rewards', 'event' => 'reward_reversal_required']);

        // A repeat event neither duplicates the audit row nor changes state.
        $this->rewards()->reevaluateLesson($lesson, null, 'lesson_refunded');
        $this->assertSame(1, Activity::query()->where('event', 'reward_reversal_required')->count());
    }

    public function test_insufficient_balance_reversal_fails_safely(): void
    {
        [$reward, $lesson] = $this->rewardForLesson(credited: true);
        $admin = $this->walletAdmin();

        // The referrer spends the reward before the reversal arrives.
        $wallet = Wallet::query()->where('user_id', $reward->referrer_id)->sole();
        app(WalletLedgerService::class)->debit(
            $wallet,
            $reward->reward_amount_minor,
            WalletLedgerEntryType::BookingPayment,
            $reward->referrer,
        );

        $result = $this->rewards()->reevaluateLesson($lesson, $admin, 'lesson_refunded');

        $this->assertSame(ReferralRewardStatus::Credited, $result->status);
        $this->assertSame('reversal_required', $result->hold_reason);
    }

    public function test_outcome_override_listener_rejects_or_reverses(): void
    {
        // Before credit: override away from Completed → Rejected.
        [$reward, $lesson] = $this->rewardForLesson(credited: false);

        LessonOutcomeOverridden::dispatch($lesson, LessonOutcome::Completed, LessonOutcome::StudentNoShow, 'Admin correction.');

        $this->assertSame(ReferralRewardStatus::Rejected, $reward->refresh()->status);
        $this->assertStringStartsWith('outcome_overridden_to_', (string) $reward->decision_reason);
    }

    public function test_override_toward_completed_evaluates_like_a_fresh_finalization(): void
    {
        [, $referred] = $this->attributedPair();
        $this->activeCampaign();

        $lesson = $this->completedPaidLesson($referred);

        LessonOutcomeOverridden::dispatch($lesson, LessonOutcome::StudentNoShow, LessonOutcome::Completed, 'Admin correction.');

        $this->assertSame(1, ReferralReward::query()->where('lesson_id', $lesson->id)->count());
    }
}
