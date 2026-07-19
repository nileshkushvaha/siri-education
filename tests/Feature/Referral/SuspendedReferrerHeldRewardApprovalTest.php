<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Enums\StudentStatus;
use App\Models\User;
use App\Referral\Contracts\ReferralEligibilityServiceInterface;
use App\Referral\Contracts\ReferralRewardServiceInterface;
use App\Referral\Enums\ReferralRewardStatus;
use Database\Seeders\ReferralPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Feature\Referral\Concerns\BuildsReferralRewardFixtures;
use Tests\TestCase;

/**
 * Phase 24H — GAP-013 Step 10: a suspended/archived student_status must
 * not forfeit money already owed. This held reward was already
 * calculated as owed before the referrer was suspended — approving it
 * for credit must not be rejected merely because of that later status
 * change (ReferralRewardService::assertStillCreditable() previously
 * did reject it; fixed as part of this phase).
 */
class SuspendedReferrerHeldRewardApprovalTest extends TestCase
{
    use BuildsReferralRewardFixtures;
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReferralWorld();
        $this->seed(ReferralPermissionSeeder::class);

        $this->manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->manager->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));
    }

    public function test_a_held_reward_can_still_be_approved_for_a_now_suspended_referrer(): void
    {
        [$referrer, $referred] = $this->attributedPair();
        $this->activeCampaign(['requires_fraud_review' => true]);

        $reward = app(ReferralEligibilityServiceInterface::class)
            ->evaluateCompletedLesson($this->completedPaidLesson($referred));

        // The referrer is suspended AFTER the reward was already
        // calculated and held — the money is already owed.
        $referrer->profile()->update(['student_status' => StudentStatus::Suspended]);

        $approved = app(ReferralRewardServiceInterface::class)->approveHeldReward($reward, $this->manager, 'Approving despite suspension — already owed.');

        $this->assertSame(ReferralRewardStatus::Credited, $approved->status);
        $this->assertNotNull($approved->wallet_ledger_entry_id);
    }

    public function test_a_held_reward_can_still_be_approved_for_a_now_archived_referrer(): void
    {
        [$referrer, $referred] = $this->attributedPair();
        $this->activeCampaign(['requires_fraud_review' => true]);

        $reward = app(ReferralEligibilityServiceInterface::class)
            ->evaluateCompletedLesson($this->completedPaidLesson($referred));

        $referrer->profile()->update(['student_status' => StudentStatus::Archived]);

        $approved = app(ReferralRewardServiceInterface::class)->approveHeldReward($reward, $this->manager, 'Approving despite archival — already owed.');

        $this->assertSame(ReferralRewardStatus::Credited, $approved->status);
    }
}
