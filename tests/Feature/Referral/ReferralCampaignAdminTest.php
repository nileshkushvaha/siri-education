<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Filament\Resources\ReferralCampaigns\Pages\CreateReferralCampaign;
use App\Filament\Resources\ReferralCampaigns\Pages\ListReferralCampaigns;
use App\Filament\Resources\ReferralCampaigns\ReferralCampaignResource;
use App\Models\ReferralCampaign;
use App\Models\User;
use App\Referral\Enums\ReferralCampaignStatus;
use App\Referral\Enums\ReferralRewardTiming;
use App\Referral\Enums\ReferralRewardType;
use Database\Seeders\ReferralPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReferralCampaignAdminTest extends TestCase
{
    use RefreshDatabase;

    private function manager(bool $withPermissions = true): User
    {
        if ($withPermissions) {
            $this->seed(ReferralPermissionSeeder::class);
        } else {
            Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        }

        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');

        return $manager;
    }

    public function test_manager_with_permissions_can_list_campaigns(): void
    {
        $this->actingAs($this->manager());

        $this->assertTrue(ReferralCampaignResource::canViewAny());

        $this->get(ReferralCampaignResource::getUrl('index'))->assertOk();
    }

    public function test_manager_without_permissions_is_denied_and_sees_no_navigation(): void
    {
        $this->actingAs($this->manager(withPermissions: false));

        $this->assertFalse(ReferralCampaignResource::canViewAny());

        $this->get(ReferralCampaignResource::getUrl('index'))->assertForbidden();
    }

    public function test_create_page_routes_through_the_service_and_produces_an_audited_draft(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(CreateReferralCampaign::class)
            ->fillForm([
                'name' => 'Filament Launch Campaign',
                'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'ends_at' => now()->addDays(30)->format('Y-m-d H:i:s'),
                'reward_type' => ReferralRewardType::Percentage->value,
                'reward_value' => 500,
                'min_completed_paid_lessons' => 1,
                'max_rewarded_classes' => 10,
                'reward_timing' => ReferralRewardTiming::Immediate->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $campaign = ReferralCampaign::query()->where('name', 'Filament Launch Campaign')->sole();

        $this->assertSame(ReferralCampaignStatus::Draft, $campaign->status);
        $this->assertSame(500, $campaign->reward_value);
        $this->assertNull($campaign->reward_currency_code);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'referral_campaigns',
            'event' => 'campaign_created',
        ]);
    }

    public function test_lifecycle_actions_are_service_backed_and_require_a_reason(): void
    {
        $this->actingAs($this->manager());

        $campaign = ReferralCampaign::factory()->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
        ]);

        Livewire::test(ListReferralCampaigns::class)
            ->callTableAction('activate', $campaign, data: ['reason' => 'Go live for launch week.']);

        $this->assertSame(ReferralCampaignStatus::Active, $campaign->refresh()->status);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'referral_campaigns',
            'event' => 'campaign_activated',
        ]);

        Livewire::test(ListReferralCampaigns::class)
            ->callTableAction('pause', $campaign, data: ['reason' => 'Investigating volumes.']);

        $this->assertSame(ReferralCampaignStatus::Paused, $campaign->refresh()->status);
    }

    public function test_no_delete_action_exists_and_the_policy_denies_deletion(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager);

        $campaign = ReferralCampaign::factory()->create();

        Livewire::test(ListReferralCampaigns::class)
            ->assertTableActionDoesNotExist('delete');

        // Even the full manage permission never includes delete.
        $this->assertFalse($manager->can('delete', $campaign));
        $this->assertFalse($manager->can('forceDelete', $campaign));
    }

    public function test_edit_is_hidden_for_non_editable_statuses(): void
    {
        $this->actingAs($this->manager());

        $active = ReferralCampaign::factory()->active()->create();
        $draft = ReferralCampaign::factory()->create();

        Livewire::test(ListReferralCampaigns::class)
            ->assertTableActionHidden('edit', $active)
            ->assertTableActionVisible('edit', $draft);
    }
}
