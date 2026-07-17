<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Notifications\Referral\ReferralRewardCreditedNotification;
use App\Notifications\Referral\ReferralRewardHeldNotification;
use App\Referral\Contracts\ReferralEligibilityServiceInterface;
use App\Referral\Contracts\ReferralRewardServiceInterface;
use App\Referral\Enums\ReferralRewardStatus;
use App\Referral\Events\ReferralRewardCredited;
use App\Referral\Listeners\SendReferralRewardNotifications;
use App\Referral\Support\ReferredStudentMask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Referral\Concerns\BuildsReferralRewardFixtures;
use Tests\TestCase;

class ReferralRewardNotificationsTest extends TestCase
{
    use BuildsReferralRewardFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReferralWorld();
    }

    public function test_credited_notification_is_sent_once_masked_and_without_private_data(): void
    {
        Notification::fake();

        [$referrer, $referred] = $this->attributedPair();
        $referred->forceFill(['first_name' => 'Priya', 'last_name' => 'Sharma'])->save();
        $this->activeCampaign();

        $lesson = $this->completedPaidLesson($referred);
        $reward = app(ReferralEligibilityServiceInterface::class)->evaluateCompletedLesson($lesson);
        app(ReferralRewardServiceInterface::class)->creditReward($reward);

        // Deliver via the listener (events are real; run it directly for
        // the duplicate-delivery assertion).
        $listener = app(SendReferralRewardNotifications::class);
        $event = new ReferralRewardCredited($reward->id, $referrer->id, $referred->id);
        $listener->handleCredited($event);
        $listener->handleCredited($event);

        Notification::assertSentToTimes($referrer, ReferralRewardCreditedNotification::class, 1);

        Notification::assertSentTo($referrer, ReferralRewardCreditedNotification::class, function (ReferralRewardCreditedNotification $notification): bool {
            $payload = $notification->toDatabase(new \stdClass);
            $text = json_encode($payload).$notification->maskedReferredName;

            // Masked identity only — never email, phone, full surname,
            // payment reference or lesson internals.
            return $notification->maskedReferredName === 'Priya S.'
                && ! str_contains($text, 'Sharma')
                && ! str_contains($text, '@')
                && ! str_contains($text, 'payment');
        });
    }

    public function test_held_notification_uses_neutral_wording(): void
    {
        Notification::fake();

        [$referrer, $referred] = $this->attributedPair();
        $this->activeCampaign(['requires_fraud_review' => true]);

        // The Held event fires from evaluation; queue is sync in tests.
        app(ReferralEligibilityServiceInterface::class)->evaluateCompletedLesson($this->completedPaidLesson($referred));

        Notification::assertSentTo($referrer, ReferralRewardHeldNotification::class, function (ReferralRewardHeldNotification $notification): bool {
            $text = $notification->toDatabase(new \stdClass)['message'];

            return ! str_contains(strtolower($text), 'fraud')
                && ! str_contains(strtolower($text), 'suspicious')
                && str_contains($text, 'reviewed');
        });
    }

    public function test_notification_failure_never_affects_money(): void
    {
        [$referrer, $referred] = $this->attributedPair();
        $this->activeCampaign();

        $lesson = $this->completedPaidLesson($referred);
        $reward = app(ReferralEligibilityServiceInterface::class)->evaluateCompletedLesson($lesson);
        app(ReferralRewardServiceInterface::class)->creditReward($reward);

        $this->assertSame(ReferralRewardStatus::Credited, $reward->refresh()->status);

        // Even if the listener explodes afterwards, the credit stands.
        $listener = app(SendReferralRewardNotifications::class);

        try {
            $listener->handleCredited(new ReferralRewardCredited(999999, $referrer->id, $referred->id));
        } catch (\Throwable) {
            // irrelevant — money already committed
        }

        $this->assertSame(ReferralRewardStatus::Credited, $reward->refresh()->status);
        $this->assertSame(1, WalletLedgerEntry::query()->where('entry_type', 'referral_credit')->count());
    }

    public function test_mask_rule_variants(): void
    {
        $this->assertSame('A student', ReferredStudentMask::mask(null));

        $user = User::factory()->make(['first_name' => 'Jane', 'last_name' => 'Doe']);
        $this->assertSame('Jane D.', ReferredStudentMask::mask($user));

        $user = User::factory()->make(['first_name' => 'Jane', 'last_name' => null]);
        $this->assertSame('Jane', ReferredStudentMask::mask($user));
    }
}
