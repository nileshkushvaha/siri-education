<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Models\Activity;
use App\Models\ReferralReward;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Referral\Contracts\ReferralEligibilityServiceInterface;
use App\Referral\Contracts\ReferralRewardServiceInterface;
use App\Referral\Enums\ReferralRewardStatus;
use App\Referral\Exceptions\ReferralException;
use App\Settings\GeneralSettings;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Enums\WalletStatus;
use App\Wallet\Services\WalletLedgerService;
use App\Wallet\Services\WalletService;
use Database\Seeders\ReferralPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Feature\Referral\Concerns\BuildsReferralRewardFixtures;
use Tests\TestCase;

class ReferralAdminDecisionsTest extends TestCase
{
    use BuildsReferralRewardFixtures;
    use RefreshDatabase;

    private User $manager;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReferralWorld();
        $this->seed(ReferralPermissionSeeder::class);

        $this->manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->manager->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));

        $this->superAdmin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->superAdmin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
    }

    private function rewards(): ReferralRewardServiceInterface
    {
        return app(ReferralRewardServiceInterface::class);
    }

    private function heldReward(): ReferralReward
    {
        [, $referred] = $this->attributedPair();
        $this->activeCampaign(['requires_fraud_review' => true]);

        return app(ReferralEligibilityServiceInterface::class)
            ->evaluateCompletedLesson($this->completedPaidLesson($referred));
    }

    private function creditFailedReward(): ReferralReward
    {
        [, $referred] = $this->attributedPair();
        $this->activeCampaign();

        $reward = app(ReferralEligibilityServiceInterface::class)
            ->evaluateCompletedLesson($this->completedPaidLesson($referred));

        $wallet = app(WalletService::class)->getOrCreateWallet($reward->referrer, null, $reward->referrer);
        $wallet->forceFill(['status' => WalletStatus::Closed])->save();

        return $this->rewards()->creditReward($reward)->refresh();
    }

    private function reversalRequiredReward(): ReferralReward
    {
        [, $referred] = $this->attributedPair();
        $this->activeCampaign();

        $lesson = $this->completedPaidLesson($referred);
        $reward = app(ReferralEligibilityServiceInterface::class)->evaluateCompletedLesson($lesson);
        $this->rewards()->creditReward($reward);

        // Automated invalidation without a capable actor parks it.
        return $this->rewards()->reevaluateLesson($lesson, null, 'lesson_refunded')->refresh();
    }

    // ── Held review ───────────────────────────────────────────────────────

    public function test_approval_revalidates_credits_through_the_single_path_and_audits(): void
    {
        $reward = $this->heldReward();

        $approved = $this->rewards()->approveHeldReward($reward, $this->manager, 'Fraud review clear.');

        $this->assertSame(ReferralRewardStatus::Credited, $approved->status);
        $this->assertSame($this->manager->id, $approved->decided_by);
        $this->assertNotNull($approved->wallet_ledger_entry_id);
        $this->assertSame(1, WalletLedgerEntry::query()->where('entry_type', 'referral_credit')->count());

        $this->assertDatabaseHas('activity_log', ['log_name' => 'referral_rewards', 'event' => 'reward_approved']);

        // Duplicate decision: approving again refuses (no longer Held),
        // and no second ledger entry can ever appear.
        try {
            $this->rewards()->approveHeldReward($approved->refresh(), $this->manager, 'Again.');
            $this->fail('A credited reward must not be re-approved.');
        } catch (ReferralException) {
            // expected
        }

        $this->assertSame(1, WalletLedgerEntry::query()->where('entry_type', 'referral_credit')->count());
    }

    public function test_rejection_is_terminal_audited_and_idempotent(): void
    {
        $reward = $this->heldReward();

        $rejected = $this->rewards()->rejectHeldReward($reward, $this->manager, 'Suspicious cluster confirmed.');

        $this->assertSame(ReferralRewardStatus::Rejected, $rejected->status);
        $this->assertSame($this->manager->id, $rejected->decided_by);
        $this->assertNotNull($rejected->rejected_at);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'referral_rewards', 'event' => 'reward_rejected_by_admin']);

        // Duplicate rejection returns the terminal result unchanged.
        $again = $this->rewards()->rejectHeldReward($rejected->refresh(), $this->manager, 'Duplicate click.');
        $this->assertSame(ReferralRewardStatus::Rejected, $again->status);
        $this->assertSame(1, Activity::query()->where('event', 'reward_rejected_by_admin')->count());

        // A rejected reward can never be approved afterwards.
        $this->expectException(ReferralException::class);
        $this->rewards()->approveHeldReward($again, $this->manager, 'Too late.');
    }

    public function test_keep_on_hold_is_simply_no_decision(): void
    {
        $reward = $this->heldReward();

        $this->assertSame(ReferralRewardStatus::Held, $reward->refresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->count());
    }

    public function test_decisions_require_permission_reason_and_forbid_self_decision(): void
    {
        $reward = $this->heldReward();

        $unauthorized = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        try {
            $this->rewards()->approveHeldReward($reward, $unauthorized, 'Nope.');
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            // expected
        }

        try {
            $this->rewards()->approveHeldReward($reward, $this->manager, '   ');
            $this->fail('Expected a reason to be required.');
        } catch (ReferralException) {
            // expected
        }

        // A manager who IS the referrer can never decide their own reward.
        $reward->referrer->assignRole('manager');

        try {
            $this->rewards()->approveHeldReward($reward, $reward->referrer->fresh(), 'Self service.');
            $this->fail('Self-decision must be refused.');
        } catch (ReferralException $e) {
            $this->assertStringContainsString('party to', $e->getMessage());
        }

        $this->assertSame(ReferralRewardStatus::Held, $reward->refresh()->status);
    }

    public function test_currency_mismatch_hold_cannot_be_approved_while_incompatible(): void
    {
        [, $referred] = $this->attributedPair();
        $this->activeCampaign();

        $reward = app(ReferralEligibilityServiceInterface::class)
            ->evaluateCompletedLesson($this->completedPaidLesson($referred));

        // Force the mismatch hold, then try to approve while still mismatched.
        $settings = app(GeneralSettings::class);
        $previous = $settings->default_currency;
        $settings->default_currency = 'USD';
        $settings->save();

        try {
            $held = $this->rewards()->creditReward($reward);
            $this->assertSame('currency_mismatch', $held->hold_reason);

            try {
                $this->rewards()->approveHeldReward($held->refresh(), $this->manager, 'Force it.');
                $this->fail('A still-mismatched reward must not be approvable.');
            } catch (ReferralException $e) {
                $this->assertStringContainsString('never converted', $e->getMessage());
            }

            // The correct operational exit is rejection — audited.
            $rejected = $this->rewards()->rejectHeldReward($held->refresh(), $this->manager, 'Wallet currency incompatible.');
            $this->assertSame(ReferralRewardStatus::Rejected, $rejected->status);
        } finally {
            $settings->default_currency = $previous;
            $settings->save();
        }

        $this->assertSame(0, WalletLedgerEntry::query()->where('entry_type', 'referral_credit')->count());
    }

    public function test_approval_revalidation_rejects_a_no_longer_eligible_situation(): void
    {
        $reward = $this->heldReward();

        // The lesson's payment gets refunded while the reward sat in review.
        $reward->lesson->booking->payments()->update(['status' => 'refunded']);

        $this->expectException(ReferralException::class);
        $this->expectExceptionMessageMatches('/no longer captured/');

        $this->rewards()->approveHeldReward($reward, $this->manager, 'Looks fine.');
    }

    // ── Credit-failure retry ──────────────────────────────────────────────

    public function test_retry_succeeds_after_wallet_recovers_and_is_audited_once(): void
    {
        $reward = $this->creditFailedReward();
        $this->assertSame(ReferralRewardStatus::CreditFailed, $reward->status);

        Wallet::query()->where('user_id', $reward->referrer_id)->update(['status' => WalletStatus::Active->value]);

        $result = $this->rewards()->retryFailedCredit($reward, $this->manager, 'Wallet reactivated.');

        $this->assertSame(ReferralRewardStatus::Credited, $result->status);
        $this->assertSame(1, WalletLedgerEntry::query()->where('entry_type', 'referral_credit')->count());
        $this->assertDatabaseHas('activity_log', ['log_name' => 'referral_rewards', 'event' => 'reward_retry_requested']);
    }

    public function test_failed_retry_preserves_state_without_duplicate_admin_notification(): void
    {
        $reward = $this->creditFailedReward();

        // Wallet still closed: the retry fails again, state preserved,
        // and reward_credit_failed is NOT audited a second time.
        $result = $this->rewards()->retryFailedCredit($reward, $this->manager, 'Try anyway.');

        $this->assertSame(ReferralRewardStatus::CreditFailed, $result->status);
        $this->assertSame(1, Activity::query()->where('event', 'reward_credit_failed')->count());
        $this->assertSame(0, WalletLedgerEntry::query()->where('entry_type', 'referral_credit')->count());

        // A credited reward cannot be "retried".
        Wallet::query()->where('user_id', $reward->referrer_id)->update(['status' => WalletStatus::Active->value]);
        $credited = $this->rewards()->retryFailedCredit($result->refresh(), $this->manager, 'Recovered.');

        $this->expectException(ReferralException::class);
        $this->rewards()->retryFailedCredit($credited->refresh(), $this->manager, 'Once more.');
    }

    // ── Required-reversal completion ──────────────────────────────────────

    public function test_admin_completes_a_required_reversal_exactly_once(): void
    {
        $reward = $this->reversalRequiredReward();
        $this->assertSame('reversal_required', $reward->hold_reason);

        $reversed = $this->rewards()->completeRequiredReversal($reward, $this->superAdmin, 'Refund confirmed.');

        $this->assertSame(ReferralRewardStatus::Reversed, $reversed->status);
        $this->assertNotNull($reversed->reversal_ledger_entry_id);
        $this->assertSame(0, Wallet::query()->where('user_id', $reward->referrer_id)->sole()->balance_minor);

        // Duplicate click: terminal result, still exactly one debit.
        $again = $this->rewards()->completeRequiredReversal($reversed->refresh(), $this->superAdmin, 'Again.');
        $this->assertSame(ReferralRewardStatus::Reversed, $again->status);
        $this->assertSame(1, WalletLedgerEntry::query()->where('direction', 'debit')->count());
    }

    public function test_reversal_requires_wallet_capable_authorized_admin(): void
    {
        $reward = $this->reversalRequiredReward();

        // The manager holds no ReverseReferralRewards permission.
        try {
            $this->rewards()->completeRequiredReversal($reward, $this->manager, 'Trying.');
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertSame(ReferralRewardStatus::Credited, $reward->refresh()->status);
        $this->assertSame('reversal_required', $reward->hold_reason);
    }

    public function test_insufficient_balance_keeps_the_visible_exception_state_and_audits_the_attempt(): void
    {
        $reward = $this->reversalRequiredReward();

        // The referrer spends the money before the admin acts.
        $wallet = Wallet::query()->where('user_id', $reward->referrer_id)->sole();
        app(WalletLedgerService::class)->debit(
            $wallet,
            $reward->reward_amount_minor,
            WalletLedgerEntryType::BookingPayment,
            $reward->referrer,
        );

        $result = $this->rewards()->completeRequiredReversal($reward, $this->superAdmin, 'Refund confirmed.');

        // Never falsely reversed; the failed manual attempt is audited.
        $this->assertSame(ReferralRewardStatus::Credited, $result->status);
        $this->assertSame('reversal_required', $result->hold_reason);
        $this->assertNull($result->reversal_ledger_entry_id);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'referral_rewards', 'event' => 'reward_reversal_attempt_failed']);
    }
}
