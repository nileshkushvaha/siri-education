<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Models\User;
use App\Referral\Contracts\ReferralEligibilityServiceInterface;
use App\Referral\Contracts\ReferralRewardServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Referral\Concerns\BuildsReferralRewardFixtures;
use Tests\TestCase;

class ReferFriendRewardPageTest extends TestCase
{
    use BuildsReferralRewardFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReferralWorld();
    }

    public function test_page_shows_source_backed_totals_and_masked_history(): void
    {
        [$referrer, $referred] = $this->attributedPair();
        $referred->forceFill(['first_name' => 'Priya', 'last_name' => 'Sharma', 'email' => 'priya-private@gmail.com'])->save();
        $this->activeCampaign();

        $lesson = $this->completedPaidLesson($referred);
        $reward = app(ReferralEligibilityServiceInterface::class)->evaluateCompletedLesson($lesson);
        app(ReferralRewardServiceInterface::class)->creditReward($reward);

        $response = $this->actingAs($referrer)
            ->get(route('dashboard.refer-a-friend'))
            ->assertOk()
            // Source-backed figures.
            ->assertSee('Friends joined')
            ->assertSee('Credited')
            // Masked identity, never the surname or email.
            ->assertSee('Priya S.')
            ->assertDontSee('Sharma')
            ->assertDontSee('priya-private@gmail.com');

        // No payment reference, booking price, or internal hold reason.
        $response->assertDontSee($lesson->booking->payments()->first()->idempotency_key);
        $response->assertDontSee('fraud_review');
        $response->assertDontSee('reversal_required');
    }

    public function test_referrer_sees_only_their_own_rewards(): void
    {
        [$referrerA, $referredA] = $this->attributedPair();
        [$referrerB, $referredB] = $this->attributedPair();
        $referredB->forceFill(['first_name' => 'Zubin', 'last_name' => 'Only'])->save();
        $this->activeCampaign();

        app(ReferralEligibilityServiceInterface::class)->evaluateCompletedLesson($this->completedPaidLesson($referredB));

        $this->actingAs($referrerA)
            ->get(route('dashboard.refer-a-friend'))
            ->assertOk()
            ->assertDontSee('Zubin');
    }

    public function test_summary_totals_are_currency_separated(): void
    {
        [$referrer, $referred] = $this->attributedPair();
        $this->activeCampaign();

        $reward = app(ReferralEligibilityServiceInterface::class)->evaluateCompletedLesson($this->completedPaidLesson($referred));
        app(ReferralRewardServiceInterface::class)->creditReward($reward);

        $summary = app(ReferralRewardServiceInterface::class)->summaryForReferrer($referrer);

        $this->assertSame(1, $summary['referred_students']);
        $this->assertSame(['INR' => 2500], $summary['credited_by_currency']);
        $this->assertSame([], $summary['reversed_by_currency']);
        $this->assertSame(0, $summary['held']);
    }

    public function test_empty_state_shows_no_fabricated_values(): void
    {
        $student = $this->activeStudent();

        $this->actingAs($student)
            ->get(route('dashboard.refer-a-friend'))
            ->assertOk()
            ->assertSee('Referral tracking and rewards will appear here once eligible activity occurs.')
            ->assertDontSee('Friends joined');
    }

    public function test_instructor_remains_denied(): void
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');

        $this->actingAs($instructor)->get(route('dashboard.refer-a-friend'))->assertForbidden();
    }
}
