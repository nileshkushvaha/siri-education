<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Enums\StudentStatus;
use App\Models\ReferralAttribution;
use App\Models\ReferralCode;
use App\Models\ReferralReward;
use App\Models\User;
use App\Referral\Contracts\ReferralAttributionServiceInterface;
use App\Referral\Contracts\ReferralEligibilityServiceInterface;
use App\Referral\Exceptions\ReferralException;
use Database\Seeders\ReferralPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Feature\Referral\Concerns\BuildsReferralRewardFixtures;
use Tests\TestCase;

class ReferralAttributionAdminTest extends TestCase
{
    use BuildsReferralRewardFixtures;
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReferralWorld();
        $this->seed(ReferralPermissionSeeder::class);

        $this->superAdmin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->superAdmin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
    }

    private function service(): ReferralAttributionServiceInterface
    {
        return app(ReferralAttributionServiceInterface::class);
    }

    public function test_valid_correction_before_rewards_repoints_referrer_and_code_with_audit(): void
    {
        [$oldReferrer, $referred, $attribution] = $this->attributedPair();
        $newReferrer = $this->activeStudent();

        $corrected = $this->service()->correctAttribution($attribution, $newReferrer, $this->superAdmin, 'Support ticket #123: wrong code typed.');

        $this->assertSame($newReferrer->id, $corrected->referrer_id);
        $this->assertSame($referred->id, $corrected->referred_student_id);

        // Code now points at the new referrer's own code.
        $this->assertSame($newReferrer->id, ReferralCode::query()->findOrFail($corrected->referral_code_id)->user_id);

        $this->assertDatabaseHas('activity_log', ['log_name' => 'referral_attributions', 'event' => 'attribution_corrected']);

        // Single permanent attribution row — corrected, never duplicated.
        $this->assertSame(1, ReferralAttribution::query()->where('referred_student_id', $referred->id)->count());
    }

    public function test_correction_is_refused_once_any_reward_exists(): void
    {
        [, $referred, $attribution] = $this->attributedPair();
        $this->activeCampaign();

        $reward = app(ReferralEligibilityServiceInterface::class)
            ->evaluateCompletedLesson($this->completedPaidLesson($referred));
        $this->assertNotNull($reward);

        $newReferrer = $this->activeStudent();

        try {
            $this->service()->correctAttribution($attribution, $newReferrer, $this->superAdmin, 'Attempt after rewards.');
            $this->fail('Correction must be refused once rewards exist.');
        } catch (ReferralException $e) {
            $this->assertStringContainsString('permanent financial history', $e->getMessage());
        }

        // Reward ownership was never rewritten.
        $this->assertSame($attribution->referrer_id, $reward->refresh()->referrer_id);
        $this->assertSame($attribution->referrer_id, $attribution->refresh()->referrer_id);
    }

    public function test_correction_enforces_permission_reason_and_eligibility_invariants(): void
    {
        [, $referred, $attribution] = $this->attributedPair();
        $newReferrer = $this->activeStudent();

        // Permission required — even a manager cannot correct.
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));

        try {
            $this->service()->correctAttribution($attribution, $newReferrer, $manager, 'Manager attempt.');
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            // expected
        }

        // Reason required.
        try {
            $this->service()->correctAttribution($attribution, $newReferrer, $this->superAdmin, '  ');
            $this->fail('Expected a mandatory reason.');
        } catch (ReferralException) {
            // expected
        }

        // Self-referral: the referred student can never become their own referrer.
        try {
            $this->service()->correctAttribution($attribution, $referred, $this->superAdmin, 'Self.');
            $this->fail('Self-referral correction must be refused.');
        } catch (ReferralException) {
            // expected
        }

        // Non-student new referrer refused.
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');

        try {
            $this->service()->correctAttribution($attribution, $instructor, $this->superAdmin, 'Instructor.');
            $this->fail('A non-student referrer must be refused.');
        } catch (ReferralException) {
            // expected
        }

        // Suspended student refused.
        $suspended = $this->activeStudent();
        $suspended->profile?->update(['student_status' => StudentStatus::Suspended]);

        try {
            $this->service()->correctAttribution($attribution, $suspended, $this->superAdmin, 'Suspended.');
            $this->fail('A suspended referrer must be refused.');
        } catch (ReferralException) {
            // expected
        }

        // An admin can never appoint themselves referrer.
        $adminStudent = $this->superAdmin;

        try {
            $this->service()->correctAttribution($attribution, $adminStudent, $this->superAdmin, 'Me.');
            $this->fail('Self-appointment must be refused.');
        } catch (ReferralException) {
            // expected
        }

        $this->assertSame($attribution->referrer_id, $attribution->refresh()->referrer_id);
    }

    public function test_rewards_created_after_correction_belong_to_the_new_referrer(): void
    {
        [, $referred, $attribution] = $this->attributedPair();
        $newReferrer = $this->activeStudent();
        $this->activeCampaign();

        $this->service()->correctAttribution($attribution, $newReferrer, $this->superAdmin, 'Corrected before activity.');

        $reward = app(ReferralEligibilityServiceInterface::class)
            ->evaluateCompletedLesson($this->completedPaidLesson($referred));

        $this->assertSame($newReferrer->id, $reward->referrer_id);
        $this->assertSame(1, ReferralReward::query()->count());
    }
}
