<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Navigation;

use App\Filament\Navigation\NavigationRegistry;
use App\Filament\Resources\Academic\AcademicCategoryResource;
use App\Filament\Resources\Countries\CountryResource;
use App\Filament\Resources\OperationalAlerts\OperationalAlertResource;
use App\Filament\Resources\ReferralCampaigns\ReferralCampaignResource;
use App\Filament\Resources\ReviewTags\ReviewTagResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Tags\TagResource;
use App\Filament\Resources\TeacherAvailability\TeacherAvailabilityResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Wallets\WalletResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Proves the centralized admin navigation registry is
 * additive-only:
 *
 *  - Every one of the ~100 registered destinations still resolves
 *    (canAccess() true) for a super administrator, exactly as it did
 *    before the regroup — this is the "nothing was lost" guarantee.
 *  - The destinations Filament actually renders for that super admin
 *    carry the new group/label from NavigationRegistry.
 *  - Group display order matches AdminPanelProvider's declared order.
 *  - A representative sample (one per top-level section, chosen for
 *    having no heavy report/fixture dependencies) round-trips over
 *    HTTP as 200 — proving this isn't just a navigation-label artifact.
 *  - Destinations relocated from Platform/System/Users & Access/Reports/
 *    etc. into their new section keep their exact pre-existing URL
 *    (each already hardcodes its own `$slug` — regrouping never
 *    touches routing).
 *  - Unauthorized users and guests remain exactly as locked out as
 *    before; navigation visibility is never the actual gate.
 *  - Empty sections (a group whose only children are all hidden for
 *    the current user) don't render at all.
 */
class AdminNavigationCoverageTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $this->superAdmin->assignRole('super_admin');
    }

    public function test_every_registered_destination_class_is_a_resource_or_page(): void
    {
        foreach (array_keys(NavigationRegistry::destinations()) as $class) {
            $isResource = is_subclass_of($class, Resource::class);
            $isPage = is_subclass_of($class, Page::class);

            $this->assertTrue(
                $isResource || $isPage,
                "[{$class}] is neither a Filament Resource nor a Filament Page."
            );
        }
    }

    /**
     * The core "nothing was lost" guarantee: every destination that was
     * navigable before this redesign is still navigable now, for the
     * same super-admin who could see everything before.
     */
    public function test_super_admin_retains_access_to_every_registered_destination(): void
    {
        $this->actingAs($this->superAdmin);

        $denied = [];

        foreach (array_keys(NavigationRegistry::destinations()) as $class) {
            if (! $class::canAccess()) {
                $denied[] = $class;
            }
        }

        $this->assertEmpty(
            $denied,
            'Super admin lost access to: '.implode(', ', $denied)
        );
    }

    /**
     * Every registered destination must produce a URL without throwing —
     * this catches a broken/renamed route independently of canAccess(),
     * since the two are checked by different Filament code paths.
     */
    public function test_every_registered_destination_resolves_a_url(): void
    {
        foreach (array_keys(NavigationRegistry::destinations()) as $class) {
            $url = is_subclass_of($class, Resource::class)
                ? $class::getUrl('index')
                : $class::getUrl();

            $this->assertNotEmpty($url, "[{$class}] resolved an empty URL.");
        }
    }

    public function test_navigation_group_display_order_matches_declared_order(): void
    {
        $declaredOrder = [
            'People',
            'Academics',
            'Operations',
            'Finance',
            'Growth',
            'Content & Communication',
            'Quality & Compliance',
            'Analytics',
            'Reference Data',
            'Access Control',
            'System',
            'Settings',
        ];

        $panelGroups = array_values(array_filter(
            Filament::getPanel('admin')->getNavigationGroups(),
            fn ($group) => $group instanceof NavigationGroup || is_string($group)
        ));

        $labels = array_map(
            fn ($group) => $group instanceof NavigationGroup ? $group->getLabel() : $group,
            $panelGroups
        );

        $this->assertSame($declaredOrder, $labels);
    }

    /**
     * Builds the real navigation tree Filament would render for the
     * super admin and checks a sample of destinations land in the
     * group/label NavigationRegistry says they should — proving the
     * registry actually drives rendering, not just documents it.
     */
    public function test_rendered_navigation_reflects_registry_group_and_label(): void
    {
        $this->actingAs($this->superAdmin);

        [$labelToGroup] = $this->renderedNavigationLabelsAndGroups();

        $expectations = [
            'All Users' => 'People',
            'Students' => 'People',
            'Instructors' => 'People',
            'Onboarding' => 'People',
            'Document Requirements' => 'People',
            'Learning Goals' => 'People',
            'Learning Plans' => 'People',
            'Lesson Packages' => 'Academics',
            'Teacher Availability' => 'Operations',
            'Support Cases' => 'Operations',
            'Instructor Earnings' => 'Finance',
            'Instructor Earnings Rules' => 'Finance',
            'RazorpayX Payout Settings' => 'Finance',
            'Advanced Finance Settings' => 'Finance',
            'Wallets' => 'Finance',
            'Demo Conversion Incentive' => 'Growth',
            'SEO' => 'Content & Communication',
            'Mail' => 'Content & Communication',
            'Homework Reminders' => 'Content & Communication',
            'Review Operations' => 'Quality & Compliance',
            'Review Tags' => 'Quality & Compliance',
            'Compliance Flags' => 'Quality & Compliance',
            'Reporting Hub' => 'Analytics',
            'Review & Quality Configuration' => 'Settings',
            'Meeting Settings' => 'Settings',
            'Countries' => 'Reference Data',
            'Roles' => 'Access Control',
            'Activity Log' => 'System',
            'Application Performance' => 'System',
        ];

        foreach ($expectations as $label => $expectedGroup) {
            $this->assertArrayHasKey($label, $labelToGroup, "Navigation item \"{$label}\" was not rendered at all.");
            $this->assertSame(
                $expectedGroup,
                $labelToGroup[$label],
                "Navigation item \"{$label}\" rendered under group \"{$labelToGroup[$label]}\", expected \"{$expectedGroup}\"."
            );
        }
    }

    public function test_dashboard_remains_ungrouped(): void
    {
        $this->actingAs($this->superAdmin);

        [$labelToGroup] = $this->renderedNavigationLabelsAndGroups();

        $this->assertArrayHasKey('Dashboard', $labelToGroup);
        $this->assertNull($labelToGroup['Dashboard']);
    }

    /**
     * @return array{0: array<string, string|null>}
     */
    private function renderedNavigationLabelsAndGroups(): array
    {
        $panel = Filament::getPanel('admin');
        $labelToGroup = [];

        foreach ($panel->getNavigation() as $groupOrItem) {
            if ($groupOrItem instanceof NavigationGroup) {
                foreach ($groupOrItem->getItems() as $item) {
                    $labelToGroup[$item->getLabel()] = $groupOrItem->getLabel();
                }

                continue;
            }

            // Ungrouped top-level item (e.g. Dashboard).
            $labelToGroup[$groupOrItem->getLabel()] = null;
        }

        return [$labelToGroup];
    }

    /**
     * One resource per top-level section, chosen for having no heavy
     * report/settings-seed dependencies, round-tripped for real over
     * HTTP to prove the route (not just canAccess()) still works.
     */
    public static function representativeDestinationProvider(): array
    {
        return [
            'People → Users' => [UserResource::class],
            'Academics → Academic Categories' => [AcademicCategoryResource::class],
            'Operations → Teacher Availability' => [TeacherAvailabilityResource::class],
            'Finance → Wallets' => [WalletResource::class],
            'Growth → Referral Campaigns' => [ReferralCampaignResource::class],
            'Content & Communication → Tags' => [TagResource::class],
            'Quality & Compliance → Review Tags' => [ReviewTagResource::class],
            'Settings → Countries' => [CountryResource::class],
        ];
    }

    #[DataProvider('representativeDestinationProvider')]
    public function test_representative_destination_resolves_over_http(string $resourceClass): void
    {
        $this->actingAs($this->superAdmin)
            ->get($resourceClass::getUrl('index'))
            ->assertOk();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function relocatedSettingsSlugProvider(): array
    {
        return [
            'Meeting Settings (Platform → Settings/Platform)' => ['/admin/settings/meetings'],
            'RazorpayX Payout Settings (Platform → Finance/Finance Configuration)' => ['/admin/settings/razorpayx-payout'],
            'Review & Quality Configuration (Platform → Settings/Quality)' => ['/admin/settings/reviews-quality'],
            'Instructor Earnings Rules (Platform → Finance)' => ['/admin/settings/instructor-earnings'],
            'Homework Reminders (Platform → Content & Communication)' => ['/admin/settings/homework-reminders'],
            'Demo Conversion Incentive (Platform → Growth)' => ['/admin/settings/demo-conversion-incentive'],
            'SEO (Platform → Content & Communication)' => ['/admin/settings/seo'],
            'Mail (Platform → Content & Communication)' => ['/admin/settings/mail'],
        ];
    }

    #[DataProvider('relocatedSettingsSlugProvider')]
    public function test_relocated_destination_keeps_its_exact_original_url(string $url): void
    {
        $this->actingAs($this->superAdmin)
            ->get($url)
            ->assertOk();
    }

    public function test_unauthorized_user_cannot_access_permission_gated_destinations(): void
    {
        $unauthorized = User::factory()->create(['status' => 'active']);
        $this->actingAs($unauthorized);

        $this->assertFalse(UserResource::canAccess());
        $this->assertFalse(RoleResource::canAccess());
        $this->assertFalse(OperationalAlertResource::canAccess());
    }

    public function test_guest_is_redirected_away_from_admin_destinations(): void
    {
        $this->get(UserResource::getUrl('index'))->assertRedirect();
        $this->get('/admin/settings/meetings')->assertRedirect();
    }

    public function test_navigation_group_active_state_follows_its_child_item(): void
    {
        $this->actingAs($this->superAdmin);
        $this->get(TeacherAvailabilityResource::getUrl('index'))->assertOk();

        $operations = collect(Filament::getPanel('admin')->getNavigation())
            ->first(fn ($groupOrItem) => $groupOrItem instanceof NavigationGroup && $groupOrItem->getLabel() === 'Operations');

        $this->assertNotNull($operations, 'Operations navigation group not found.');
        $this->assertTrue($operations->isActive(), 'Operations group did not report active state on its own page.');
    }

    /**
     * A group whose every child is hidden from the current user must
     * not render an empty section header.
     */
    public function test_groups_with_no_visible_children_are_not_rendered_for_a_locked_down_user(): void
    {
        $lockedDown = User::factory()->create(['status' => 'active']);
        $this->actingAs($lockedDown);

        $groupLabels = collect(Filament::getPanel('admin')->getNavigation())
            ->filter(fn ($groupOrItem) => $groupOrItem instanceof NavigationGroup)
            ->map(fn ($group) => $group->getLabel())
            ->all();

        // A brand-new user with no role/permissions should not see the
        // Settings section at all (every child requires super_admin or
        // an explicit permission) — the group itself must be absent,
        // not rendered empty.
        $this->assertNotContains('Settings', $groupLabels);
    }
}
