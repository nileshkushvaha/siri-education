<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Filament\Pages\ReferralCommunicationReports;
use App\Filament\Pages\ReportingHub;
use App\Models\User;
use App\Reporting\Contracts\ReportRegistryInterface;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Referrals, Quality & Communications page: independent
 * section gating, honest structural-absence messaging, no mutation
 * actions, Livewire hydration safety and registry/hub integration.
 */
class ReferralCommunicationPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
    }

    private function manager(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    // ── Access & section gating ───────────────────────────────────────────

    public function test_manager_can_open_the_page_with_all_three_sections(): void
    {
        $this->actingAs($this->manager())->get(ReferralCommunicationReports::getUrl())
            ->assertOk()
            ->assertSee('Live query')
            ->assertSee('Referral rewards (wallet-ledger confirmed)')
            ->assertSee('Review submission')
            ->assertSee('Notification activity');
    }

    public function test_user_with_no_relevant_permission_is_denied(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)->get(ReferralCommunicationReports::getUrl())->assertForbidden();
    }

    public function test_sections_disappear_independently_with_their_permissions(): void
    {
        $admin = $this->manager();
        Role::findByName('manager', 'web')->revokePermissionTo('ViewReferralReports');
        Role::findByName('manager', 'web')->revokePermissionTo('ViewNotificationReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin)->get(ReferralCommunicationReports::getUrl())
            ->assertOk()
            ->assertDontSee('Referral rewards (wallet-ledger confirmed)')
            ->assertDontSee('Notification activity')
            ->assertSee('Review submission');
    }

    public function test_page_is_denied_when_all_three_permissions_are_absent(): void
    {
        $admin = $this->manager();
        foreach (['ViewReferralReports', 'ViewReviewQualityReports', 'ViewNotificationReports'] as $permission) {
            Role::findByName('manager', 'web')->revokePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin)->get(ReferralCommunicationReports::getUrl())->assertForbidden();
    }

    // ── Honest absence messaging & no mutation affordances ────────────────

    public function test_structural_absences_are_stated_never_fabricated(): void
    {
        // The page reports real attribution/reward figures — but
        // conversion rate is honestly absent (its §4A definition gate
        // is closed) and referred booking value is never labeled revenue.
        $this->actingAs($this->manager())->get(ReferralCommunicationReports::getUrl())
            ->assertOk()
            ->assertSee('conversion rate remains')
            ->assertSee('no agreed qualifying-event denominator')
            ->assertSee('delivery rate, provider performance')
            ->assertSee('Messaging analytics are unavailable');
    }

    public function test_no_mutation_action_renders(): void
    {
        $this->actingAs($this->manager())->get(ReferralCommunicationReports::getUrl())
            ->assertOk()
            ->assertDontSee('Approve')
            ->assertDontSee('Retry')
            ->assertDontSee('Moderate')
            ->assertDontSee('Resolve');
    }

    // ── Livewire hydration safety ─────────────────────────────────────────

    public function test_string_filter_hydration_never_throws(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(ReferralCommunicationReports::class)
            ->set('currencyCode', 'INR')
            ->set('instructorId', '123')
            ->set('periodPreset', 'last_7_days')
            ->call('resetFilters')
            ->assertSet('currencyCode', null)
            ->assertOk();
    }

    // ── Registry & hub ────────────────────────────────────────────────────

    public function test_all_three_reports_are_available_with_real_routes(): void
    {
        $registry = app(ReportRegistryInterface::class);

        foreach ([
            'referral_activity' => 'ViewReferralReports',
            'review_quality_analytics' => 'ViewReviewQualityReports',
            'notification_delivery' => 'ViewNotificationReports',
        ] as $key => $permission) {
            $definition = $registry->find($key);

            $this->assertNotNull($definition, $key);
            $this->assertTrue($definition->available, "{$key} must be available.");
            $this->assertSame($permission, $definition->requiredViewPermission);
            $this->assertSame(ReferralCommunicationReports::class, $definition->routeName);
        }
    }

    public function test_reporting_hub_lists_the_new_reports(): void
    {
        $this->actingAs($this->manager())->get(ReportingHub::getUrl())
            ->assertOk()
            ->assertSee('Referral Activity')
            ->assertSee('Review &amp; Quality Analytics', false)
            ->assertSee('Notification Activity');
    }
}
