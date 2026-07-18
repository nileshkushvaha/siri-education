<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Filament\Resources\ReferralAttributions\Pages\ListReferralAttributions;
use App\Filament\Resources\ReferralAttributions\ReferralAttributionResource;
use App\Filament\Resources\ReferralCodes\Pages\ListReferralCodes;
use App\Filament\Resources\ReferralCodes\ReferralCodeResource;
use App\Filament\Resources\ReferralRewards\Pages\ListReferralRewards;
use App\Filament\Resources\ReferralRewards\ReferralRewardResource;
use App\Models\ReferralCode;
use App\Models\User;
use App\Referral\Contracts\ReferralAttributionServiceInterface;
use App\Referral\Contracts\ReferralEligibilityServiceInterface;
use App\Referral\Contracts\ReferralRewardServiceInterface;
use App\Referral\Enums\ReferralCodeStatus;
use App\Referral\Enums\ReferralRewardStatus;
use Database\Seeders\ReferralPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Referral\Concerns\BuildsReferralRewardFixtures;
use Tests\TestCase;

class ReferralOperationsFilamentTest extends TestCase
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

    public function test_manager_can_open_all_three_operations_surfaces(): void
    {
        $this->actingAs($this->manager);

        $this->assertTrue(ReferralRewardResource::canViewAny());
        $this->assertTrue(ReferralAttributionResource::canViewAny());
        $this->assertTrue(ReferralCodeResource::canViewAny());

        $this->get(ReferralRewardResource::getUrl('index'))->assertOk();
        $this->get(ReferralAttributionResource::getUrl('index'))->assertOk();
        $this->get(ReferralCodeResource::getUrl('index'))->assertOk();
    }

    public function test_manager_without_permissions_is_denied_everywhere(): void
    {
        $bare = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $bare->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));
        $bare->revokePermissionTo(['ViewReferralRewards', 'ViewReferralAttributions', 'ViewReferralCodes']);

        // Revoke via a fresh role-less manager instead: role grants win, so build a
        // permissionless admin-portal user through a bespoke role.
        $role = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $role->revokePermissionTo(['ViewReferralRewards', 'ViewReferralAttributions', 'ViewReferralCodes']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($bare);

        $this->assertFalse(ReferralRewardResource::canViewAny());
        $this->assertFalse(ReferralAttributionResource::canViewAny());
        $this->assertFalse(ReferralCodeResource::canViewAny());

        $this->get(ReferralRewardResource::getUrl('index'))->assertForbidden();
        $this->get(ReferralAttributionResource::getUrl('index'))->assertForbidden();
        $this->get(ReferralCodeResource::getUrl('index'))->assertForbidden();
    }

    public function test_reward_actions_are_service_backed_and_state_gated(): void
    {
        [, $referred] = $this->attributedPair();
        $this->activeCampaign(['requires_fraud_review' => true]);

        $reward = app(ReferralEligibilityServiceInterface::class)
            ->evaluateCompletedLesson($this->completedPaidLesson($referred));

        $this->actingAs($this->manager);

        Livewire::test(ListReferralRewards::class)
            ->assertTableActionVisible('approve', $reward)
            ->assertTableActionVisible('reject', $reward)
            ->assertTableActionHidden('retry_credit', $reward)
            ->assertTableActionHidden('complete_reversal', $reward)
            ->assertTableActionDoesNotExist('delete')
            ->assertTableActionDoesNotExist('edit')
            ->callTableAction('approve', $reward, data: ['reason' => 'Review clear — approving.']);

        $this->assertSame(ReferralRewardStatus::Credited, $reward->refresh()->status);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'referral_rewards', 'event' => 'reward_approved']);
    }

    public function test_reversal_action_is_hidden_from_managers_without_the_permission(): void
    {
        [, $referred] = $this->attributedPair();
        $this->activeCampaign();

        $lesson = $this->completedPaidLesson($referred);
        $reward = app(ReferralEligibilityServiceInterface::class)->evaluateCompletedLesson($lesson);
        app(ReferralRewardServiceInterface::class)->creditReward($reward);
        app(ReferralRewardServiceInterface::class)->reevaluateLesson($lesson, null, 'lesson_refunded');

        $this->actingAs($this->manager);

        Livewire::test(ListReferralRewards::class)
            ->assertTableActionHidden('complete_reversal', $reward->refresh());
    }

    public function test_attribution_listing_masks_identity_and_correct_action_is_permission_gated(): void
    {
        [, $referred, $attribution] = $this->attributedPair();
        $referred->forceFill(['first_name' => 'Priya', 'last_name' => 'Sharma', 'email' => 'priya-hidden@gmail.com'])->save();

        $this->actingAs($this->manager);

        $this->get(ReferralAttributionResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Priya S.')
            ->assertDontSee('priya-hidden@gmail.com');

        // The manager lacks CorrectReferralAttribution: action hidden.
        Livewire::test(ListReferralAttributions::class)
            ->assertTableActionHidden('correct', $attribution)
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete');
    }

    public function test_code_disable_flows_through_the_service_and_no_other_mutation_exists(): void
    {
        $student = $this->activeStudent();
        $code = ReferralCode::factory()->create(['user_id' => $student->id]);

        $this->actingAs($this->manager);

        Livewire::test(ListReferralCodes::class)
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete');

        // The manager lacks DisableReferralCodes (ungranted by default).
        Livewire::test(ListReferralCodes::class)
            ->assertTableActionHidden('disable', $code);

        // A super_admin can disable; the attribution/reward history of a
        // disabled code stays untouched and it never re-enables.
        $superAdmin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $superAdmin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

        $this->actingAs($superAdmin);

        Livewire::test(ListReferralCodes::class)
            ->callTableAction('disable', $code, data: ['reason' => 'Code shared in spam channels.']);

        $this->assertSame(ReferralCodeStatus::Disabled, $code->refresh()->status);

        // Disabled code is refused for NEW attribution (locked re-check).
        $newStudent = $this->activeStudent();
        $attribution = app(ReferralAttributionServiceInterface::class)
            ->attributeFromRegistration($newStudent, $code->code);

        $this->assertNull($attribution);
    }
}
