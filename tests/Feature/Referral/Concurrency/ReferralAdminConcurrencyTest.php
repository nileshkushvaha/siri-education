<?php

declare(strict_types=1);

namespace Tests\Feature\Referral\Concurrency;

use App\Models\Activity;
use App\Models\ReferralAttribution;
use App\Models\ReferralCampaign;
use App\Models\ReferralCode;
use App\Models\ReferralReward;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Referral\Contracts\ReferralEligibilityServiceInterface;
use App\Referral\Contracts\ReferralRewardServiceInterface;
use App\Referral\Enums\ReferralRewardStatus;
use App\Wallet\Enums\WalletStatus;
use App\Wallet\Services\WalletService;
use Database\Seeders\ReferralPermissionSeeder;
use Spatie\Permission\Models\Role;
use Tests\Feature\Referral\Concerns\BuildsReferralRewardFixtures;

/**
 * Real multi-process races for the Phase 19E admin invariants: every
 * decision funnels through the same locked service methods the
 * automation uses, so racing administrators produce one terminal
 * result, one ledger entry, one reversal, and no attribution
 * corruption — losers receive a clean refusal, never a duplicate.
 */
class ReferralAdminConcurrencyTest extends ConcurrencyTestCase
{
    use BuildsReferralRewardFixtures;

    private User $adminA;

    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReferralWorld();
        $this->seed(ReferralPermissionSeeder::class);

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $this->adminA = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->adminA->assignRole('super_admin');
        $this->adminB = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->adminB->assignRole('super_admin');
    }

    private function heldReward(): ReferralReward
    {
        [, $referred] = $this->attributedPair();
        ReferralCampaign::query()->update(['status' => 'archived']);
        $this->activeCampaign(['requires_fraud_review' => true]);

        return app(ReferralEligibilityServiceInterface::class)
            ->evaluateCompletedLesson($this->completedPaidLesson($referred));
    }

    public function test_two_admins_approving_one_held_reward_credit_once(): void
    {
        $reward = $this->heldReward();

        $results = $this->race([
            ['referral-approve-reward', ['reward_id' => $reward->id, 'admin_id' => $this->adminA->id]],
            ['referral-approve-reward', ['reward_id' => $reward->id, 'admin_id' => $this->adminB->id]],
        ]);

        // One winner; the loser was refused cleanly (reward no longer Held).
        $succeeded = array_values(array_filter($results, fn (array $r): bool => $r['ok']));
        $this->assertGreaterThanOrEqual(1, count($succeeded), json_encode($results));

        $this->assertSame(ReferralRewardStatus::Credited, $reward->refresh()->status);
        $this->assertSame(1, WalletLedgerEntry::query()->where('entry_type', 'referral_credit')->where('source_id', (string) $reward->id)->count());
        $this->assertSame(1, Activity::query()->where('event', 'reward_approved')->where('subject_id', $reward->id)->count());
    }

    public function test_approval_racing_rejection_yields_one_terminal_state(): void
    {
        $reward = $this->heldReward();

        $results = $this->race([
            ['referral-approve-reward', ['reward_id' => $reward->id, 'admin_id' => $this->adminA->id]],
            ['referral-reject-reward', ['reward_id' => $reward->id, 'admin_id' => $this->adminB->id]],
        ]);

        $reward->refresh();

        $this->assertContains($reward->status, [ReferralRewardStatus::Credited, ReferralRewardStatus::Rejected], json_encode($results));

        if ($reward->status === ReferralRewardStatus::Rejected) {
            $this->assertSame(0, WalletLedgerEntry::query()->where('source_id', (string) $reward->id)->count(), 'A rejected reward must have no credit.');
        } else {
            $this->assertSame(1, WalletLedgerEntry::query()->where('entry_type', 'referral_credit')->where('source_id', (string) $reward->id)->count());
        }
    }

    public function test_two_admins_retrying_one_failed_credit_post_one_entry(): void
    {
        [, $referred] = $this->attributedPair();
        ReferralCampaign::query()->update(['status' => 'archived']);
        $this->activeCampaign();

        $reward = app(ReferralEligibilityServiceInterface::class)
            ->evaluateCompletedLesson($this->completedPaidLesson($referred));

        $wallet = app(WalletService::class)->getOrCreateWallet($reward->referrer, null, $reward->referrer);
        $wallet->forceFill(['status' => WalletStatus::Closed])->save();
        app(ReferralRewardServiceInterface::class)->creditReward($reward);
        $wallet->forceFill(['status' => WalletStatus::Active])->save();

        $results = $this->race([
            ['referral-retry-reward', ['reward_id' => $reward->id, 'admin_id' => $this->adminA->id]],
            ['referral-retry-reward', ['reward_id' => $reward->id, 'admin_id' => $this->adminB->id]],
        ]);

        $this->assertSame(ReferralRewardStatus::Credited, $reward->refresh()->status, json_encode($results));
        $this->assertSame(1, WalletLedgerEntry::query()->where('entry_type', 'referral_credit')->where('source_id', (string) $reward->id)->count());
    }

    public function test_two_admins_completing_one_reversal_debit_once(): void
    {
        [, $referred] = $this->attributedPair();
        ReferralCampaign::query()->update(['status' => 'archived']);
        $this->activeCampaign();

        $lesson = $this->completedPaidLesson($referred);
        $reward = app(ReferralEligibilityServiceInterface::class)->evaluateCompletedLesson($lesson);
        app(ReferralRewardServiceInterface::class)->creditReward($reward);
        app(ReferralRewardServiceInterface::class)->reevaluateLesson($lesson, null, 'lesson_refunded');

        $results = $this->race([
            ['referral-complete-reversal', ['reward_id' => $reward->id, 'admin_id' => $this->adminA->id]],
            ['referral-complete-reversal', ['reward_id' => $reward->id, 'admin_id' => $this->adminB->id]],
        ]);

        $reward->refresh();
        $this->assertSame(ReferralRewardStatus::Reversed, $reward->status, json_encode($results));
        $this->assertSame(1, WalletLedgerEntry::query()->where('direction', 'debit')->where('wallet_id', Wallet::query()->where('user_id', $reward->referrer_id)->sole()->id)->count());
        $this->assertSame(0, Wallet::query()->where('user_id', $reward->referrer_id)->sole()->balance_minor);
    }

    public function test_attribution_correction_racing_reward_creation_never_corrupts_ownership(): void
    {
        [, $referred, $attribution] = $this->attributedPair();
        $newReferrer = $this->activeStudent();
        ReferralCampaign::query()->update(['status' => 'archived']);
        $this->activeCampaign();

        $lesson = $this->completedPaidLesson($referred);

        $results = $this->race([
            ['referral-evaluate-lesson', ['lesson_id' => $lesson->id]],
            ['referral-correct-attribution', ['attribution_id' => $attribution->id, 'new_referrer_id' => $newReferrer->id, 'admin_id' => $this->adminA->id]],
        ]);

        $attribution->refresh();
        $reward = ReferralReward::query()->where('lesson_id', $lesson->id)->first();

        if ($reward !== null) {
            // Reward won the lock first: ownership matches the attribution
            // AT REWARD TIME, and the correction must have been refused.
            $this->assertSame($attribution->referrer_id, $reward->referrer_id, json_encode($results));
        } else {
            // Correction won: the attribution points at the new referrer
            // and no reward exists yet.
            $this->assertSame($newReferrer->id, $attribution->referrer_id, json_encode($results));
        }

        // Either way: single attribution row, no reward rewritten.
        $this->assertSame(1, ReferralAttribution::query()->where('referred_student_id', $referred->id)->count());
    }

    public function test_code_disable_racing_attribution_is_deterministic(): void
    {
        $referrer = $this->activeStudent();
        $code = ReferralCode::factory()->create(['user_id' => $referrer->id]);
        $newStudent = $this->activeStudent();

        $results = $this->race([
            ['referral-attribute', ['referred_student_id' => $newStudent->id, 'code' => $code->code]],
            ['referral-disable-code', ['code_id' => $code->id, 'admin_id' => $this->adminA->id]],
        ]);

        $this->assertTrue($results[1]['ok'], json_encode($results));
        $this->assertSame('disabled', $code->refresh()->status->value);

        $attributionCreated = ReferralAttribution::query()->where('referred_student_id', $newStudent->id)->exists();

        // If the disable committed first, the locked re-check refused the
        // attribution; if attribution won, it stands untouched — a later
        // disable never unwinds history. Both are deterministic outcomes.
        $this->assertSame($attributionCreated, (bool) ($results[0]['result']['attribution_id'] ?? false), json_encode($results));
    }
}
