<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Models\ReferralReward;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Referral\Contracts\ReferralEligibilityServiceInterface;
use App\Referral\Contracts\ReferralRewardServiceInterface;
use App\Referral\Enums\ReferralRewardStatus;
use App\Settings\GeneralSettings;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Enums\WalletStatus;
use App\Wallet\Services\WalletService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Referral\Concerns\BuildsReferralRewardFixtures;
use Tests\TestCase;

class ReferralRewardCreditTest extends TestCase
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

    private function eligibleReward(array $campaignOverrides = []): ReferralReward
    {
        [, $referred] = $this->attributedPair();
        $this->activeCampaign($campaignOverrides);

        return app(ReferralEligibilityServiceInterface::class)
            ->evaluateCompletedLesson($this->completedPaidLesson($referred));
    }

    public function test_credit_posts_exactly_one_ledger_entry_with_deterministic_key(): void
    {
        $reward = $this->eligibleReward();

        $credited = $this->rewards()->creditReward($reward);

        $this->assertSame(ReferralRewardStatus::Credited, $credited->status);
        $this->assertNotNull($credited->credited_at);

        $entry = WalletLedgerEntry::query()->findOrFail($credited->wallet_ledger_entry_id);

        $this->assertSame(WalletLedgerEntryType::ReferralCredit, $entry->entry_type);
        $this->assertSame('referral_reward:'.$credited->id, $entry->idempotency_key);
        $this->assertSame('referral_reward', $entry->source_type);
        $this->assertSame((string) $credited->id, $entry->source_id);
        $this->assertSame(2500, $entry->amount_minor);
        $this->assertSame('INR', $entry->currency_code);
        $this->assertSame($credited->referrer_id, $entry->user_id);

        // Ledger metadata carries internal references only — never the
        // referred student's identity.
        $this->assertArrayNotHasKey('referred_student_email', $entry->metadata ?? []);
        $this->assertSame($credited->campaign_id, $entry->metadata['campaign_id']);
    }

    public function test_repeated_credit_calls_never_double_credit(): void
    {
        $reward = $this->eligibleReward();

        $this->rewards()->creditReward($reward);
        $this->rewards()->creditReward($reward->refresh());

        $this->assertSame(1, WalletLedgerEntry::query()->where('entry_type', 'referral_credit')->count());

        $wallet = Wallet::query()->where('user_id', $reward->referrer_id)->sole();
        $this->assertSame(2500, $wallet->balance_minor);
    }

    public function test_future_ready_and_held_rewards_are_never_credited(): void
    {
        $reward = $this->eligibleReward();
        $reward->forceFill(['credit_ready_at' => now()->addDays(3)])->save();

        $this->assertSame(ReferralRewardStatus::Eligible, $this->rewards()->creditReward($reward)->status);

        $reward->forceFill(['status' => ReferralRewardStatus::Held, 'hold_reason' => 'fraud_review', 'credit_ready_at' => now()])->save();

        $this->assertSame(ReferralRewardStatus::Held, $this->rewards()->creditReward($reward->refresh())->status);
        $this->assertSame(0, WalletLedgerEntry::query()->count());
    }

    public function test_currency_mismatch_holds_the_reward_instead_of_converting(): void
    {
        $reward = $this->eligibleReward(); // INR reward

        // The referrer has no wallet yet and no profile country — wallet
        // resolution follows the platform default. Point that at USD so
        // the referrer's default wallet cannot match the INR reward.
        $settings = app(GeneralSettings::class);
        $previous = $settings->default_currency;
        $settings->default_currency = 'USD';
        $settings->save();

        try {
            $held = $this->rewards()->creditReward($reward);
        } finally {
            $settings->default_currency = $previous;
            $settings->save();
        }

        $this->assertSame(ReferralRewardStatus::Held, $held->status);
        $this->assertSame('currency_mismatch', $held->hold_reason);
        $this->assertSame(0, WalletLedgerEntry::query()->where('entry_type', 'referral_credit')->count());
        $this->assertSame('USD', Wallet::query()->where('user_id', $reward->referrer_id)->sole()->currency_code);
    }

    public function test_frozen_wallet_still_accepts_the_credit(): void
    {
        $reward = $this->eligibleReward();

        $wallet = app(WalletService::class)->getOrCreateWallet($reward->referrer, null, $reward->referrer);
        $wallet->forceFill(['status' => WalletStatus::Frozen])->save();

        $credited = $this->rewards()->creditReward($reward);

        $this->assertSame(ReferralRewardStatus::Credited, $credited->status);
    }

    public function test_closed_wallet_produces_a_retryable_credit_failed_state(): void
    {
        $reward = $this->eligibleReward();

        $wallet = app(WalletService::class)->getOrCreateWallet($reward->referrer, null, $reward->referrer);
        $wallet->forceFill(['status' => WalletStatus::Closed])->save();

        $failed = $this->rewards()->creditReward($reward);

        $this->assertSame(ReferralRewardStatus::CreditFailed, $failed->status);
        $this->assertSame('wallet_unusable', $failed->hold_reason);
        $this->assertNull($failed->wallet_ledger_entry_id);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'referral_rewards', 'event' => 'reward_credit_failed']);

        // Wallet becomes usable again → the same reward credits cleanly.
        $wallet->forceFill(['status' => WalletStatus::Active])->save();

        $this->assertSame(ReferralRewardStatus::Credited, $this->rewards()->creditReward($failed->refresh())->status);
    }

    public function test_sweep_credits_only_ready_rewards_in_bounded_batches(): void
    {
        // Ready reward.
        $ready = $this->eligibleReward();

        // Future-ready reward (different referred student, same campaign).
        [, $referredB] = $this->attributedPair();
        $future = app(ReferralEligibilityServiceInterface::class)
            ->evaluateCompletedLesson($this->completedPaidLesson($referredB));
        $future->forceFill(['credit_ready_at' => now()->addDays(5)])->save();

        // Held reward (third referred student).
        [, $referredC] = $this->attributedPair();
        $held = app(ReferralEligibilityServiceInterface::class)
            ->evaluateCompletedLesson($this->completedPaidLesson($referredC));
        $held->forceFill(['status' => ReferralRewardStatus::Held, 'hold_reason' => 'fraud_review'])->save();

        Artisan::call('referrals:credit-eligible-rewards');

        $this->assertSame(ReferralRewardStatus::Credited, $ready->refresh()->status);
        $this->assertSame(ReferralRewardStatus::Eligible, $future->refresh()->status);
        $this->assertSame(ReferralRewardStatus::Held, $held->refresh()->status);

        // Re-running is a no-op — still exactly one ledger entry.
        Artisan::call('referrals:credit-eligible-rewards');
        $this->assertSame(1, WalletLedgerEntry::query()->where('entry_type', 'referral_credit')->count());
    }

    public function test_sweep_respects_the_batch_limit(): void
    {
        $this->eligibleReward();

        [, $referredB] = $this->attributedPair();
        app(ReferralEligibilityServiceInterface::class)
            ->evaluateCompletedLesson($this->completedPaidLesson($referredB));

        Artisan::call('referrals:credit-eligible-rewards', ['--limit' => 1]);

        $this->assertSame(1, ReferralReward::query()->where('status', ReferralRewardStatus::Credited)->count());

        Artisan::call('referrals:credit-eligible-rewards');

        $this->assertSame(2, ReferralReward::query()->where('status', ReferralRewardStatus::Credited)->count());
    }

    public function test_scheduler_registers_the_command_exactly_once(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'referrals:credit-eligible-rewards'));

        $this->assertCount(1, $events);
    }
}
