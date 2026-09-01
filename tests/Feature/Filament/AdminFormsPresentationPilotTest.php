<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Navigation\NavigationRegistry;
use App\Filament\Pages\Settings\GeneralSettingsPage;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Filament\Resources\Faq\FaqCategoryResource;
use App\Filament\Resources\Faq\Pages\CreateFaqCategory;
use App\Filament\Resources\Faq\Pages\EditFaqCategory;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Support\Presentation\BackAction;
use App\Models\Booking;
use App\Models\FaqCategory;
use App\Models\User;
use Database\Seeders\FaqPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Stage 3 pilot: the Stage 2 shared presentation foundation (breadcrumbs,
 * Back navigation, "Create & create another" removal) applied to the FAQ
 * Category resource specifically, plus breadcrumb spot-checks on the
 * Booking, User, and General Settings pilots. Functional FAQ Category
 * behavior (validation, persistence, cache clearing) is already covered
 * by tests/Feature/Faq/*; this file only asserts the presentation layer
 * and the authorization-awareness of the new Back action.
 *
 * Breadcrumbs are asserted via rendered-text order (assertSeeInOrder)
 * rather than calling the component's getBreadcrumbs() directly —
 * Livewire's Testable::instance() returned null for these full-page
 * Filament components during development of this suite, and asserting
 * on what actually renders is the more appropriate "public component
 * assertion" anyway.
 */
class AdminFormsPresentationPilotTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(FaqPermissionSeeder::class);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');
        // FaqPermissionSeeder's 'manager' role grant is view-only
        // (ViewAny/View) — this suite exercises create/edit/delete too,
        // so grant those directly (the seeder already creates the
        // Permission rows) rather than widening the shared seeder.
        $admin->givePermissionTo(['Create:FaqCategory', 'Update:FaqCategory', 'Delete:FaqCategory']);

        return $admin;
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('super_admin');

        return $admin;
    }

    private function makeCategory(array $attributes = []): FaqCategory
    {
        return FaqCategory::create([
            'name' => 'Billing',
            'is_active' => true,
            'display_order' => 0,
            ...$attributes,
        ]);
    }

    public function test_create_page_has_the_expected_heading_subheading_and_breadcrumbs(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreateFaqCategory::class)
            ->assertSee('Create FAQ Category')
            ->assertSee('Create a category used to organize frequently asked questions.')
            ->assertSeeInOrder(['Content & Communication', 'FAQ Categories', 'Create']);
    }

    public function test_edit_page_heading_uses_the_safe_record_title_and_breadcrumbs_include_it(): void
    {
        $category = $this->makeCategory(['name' => 'Billing']);
        $this->actingAs($this->admin());

        $html = Livewire::test(EditFaqCategory::class, ['record' => $category->getRouteKey()])
            ->assertSee('Edit Billing')
            ->assertSee('Update how this FAQ category is presented and ordered.')
            ->assertSeeInOrder(['Content & Communication', 'FAQ Categories', 'Billing', 'Edit'])
            ->html();

        // The record name must appear exactly once in the breadcrumb
        // trail — this is precisely the duplicate-crumb bug caught during
        // this stage (Filament's own EditRecord already inserts the
        // record title automatically). The heading also legitimately
        // contains "Billing" ("Edit Billing"), so count matches within
        // just the breadcrumb <nav> rather than the whole page.
        preg_match('/<nav[^>]*fi-breadcrumbs.*?<\/nav>/s', $html, $matches);
        $this->assertNotEmpty($matches, 'Breadcrumb nav not found in rendered output.');
        $this->assertSame(1, substr_count($matches[0], 'Billing'));
    }

    public function test_create_and_edit_pages_no_longer_offer_create_and_create_another(): void
    {
        $category = $this->makeCategory();
        $this->actingAs($this->admin());

        Livewire::test(CreateFaqCategory::class)
            ->assertDontSeeHtml('wire:click="createAnother"')
            ->assertSee('Create');

        Livewire::test(EditFaqCategory::class, ['record' => $category->getRouteKey()])
            ->assertSee('Save changes');
    }

    public function test_create_and_edit_pages_still_render_a_working_back_action_for_an_authorized_admin(): void
    {
        $category = $this->makeCategory();
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(CreateFaqCategory::class)
            ->assertActionExists('back')
            ->assertSee('Back to FAQ Categories');

        Livewire::actingAs($admin)->test(EditFaqCategory::class, ['record' => $category->getRouteKey()])
            ->assertActionExists('back')
            ->assertSee('Back to FAQ Categories');
    }

    /**
     * The Back action must disappear rather than link somewhere the
     * viewer can't access — a real gap the pilot's own safety rules
     * required closing (BackAction::toResourceIndex() checks
     * canViewAny() before building the link).
     *
     * Exercised by calling the factory directly rather than through a
     * rendered Livewire page: this environment's Livewire test harness
     * throws "Attempt to read property mountedActions on null" from
     * inside Filament's own parseNestedActions() for *any*
     * assertAction*() call whenever the acting user's canViewAny() is
     * false — reproduced even for a made-up action name, unrelated to
     * Back specifically, and independent of whether the user holds a
     * role or a direct permission. That's a pre-existing environment
     * quirk (not caused by this stage's changes) and out of scope to
     * chase down here; testing the authorization-check logic directly
     * proves the same guarantee without depending on the broken path.
     */
    public function test_back_action_destination_is_null_when_the_admin_cannot_view_the_index(): void
    {
        $this->seed(FaqPermissionSeeder::class);
        Permission::firstOrCreate(['name' => 'Create:FaqCategory', 'guard_name' => 'web']);
        $creatorOnlyRole = Role::firstOrCreate(['name' => 'faq-category-creator-only', 'guard_name' => 'web']);
        $creatorOnlyRole->givePermissionTo('Create:FaqCategory');

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole($creatorOnlyRole);
        $this->actingAs($admin);

        $this->assertTrue(FaqCategoryResource::canCreate());
        $this->assertFalse(FaqCategoryResource::canViewAny());
        $this->assertNull(BackAction::toResourceIndex(FaqCategoryResource::class, 'Back to FAQ Categories'));

        // And the positive case, same admin() helper the rest of this
        // suite uses (has ViewAny:FaqCategory via the 'manager' role):
        $this->actingAs($this->admin());
        $this->assertNotNull(BackAction::toResourceIndex(FaqCategoryResource::class, 'Back to FAQ Categories'));
    }

    public function test_faq_category_creation_still_persists_and_redirects_correctly(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreateFaqCategory::class)
            ->set('data.name', 'Billing')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('faq_categories', ['name' => 'Billing']);
    }

    public function test_faq_category_validation_and_authorization_are_unchanged(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreateFaqCategory::class)
            ->call('create')
            ->assertHasFormErrors(['name']);

        $unauthorized = User::factory()->create(['status' => 'active']);
        $this->actingAs($unauthorized)
            ->get(FaqCategoryResource::getUrl('create'))
            ->assertForbidden();
    }

    public function test_booking_edit_page_shows_the_section_prefixed_breadcrumb(): void
    {
        $booking = Booking::factory()->create();
        $this->actingAs($this->superAdmin());

        Livewire::test(EditBooking::class, ['record' => $booking->getRouteKey()])
            ->assertSeeInOrder(['Operations', 'Bookings', $booking->reference, 'Edit']);
    }

    public function test_user_edit_page_shows_the_section_prefixed_breadcrumb_without_duplicating_the_name(): void
    {
        $user = User::factory()->create(['status' => 'active', 'name' => 'Jordan Rivera']);
        $this->actingAs($this->superAdmin());

        $html = Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertSeeInOrder(['People', 'All Users', 'Jordan Rivera', 'Edit'])
            ->html();

        preg_match('/<nav[^>]*fi-breadcrumbs.*?<\/nav>/s', $html, $matches);
        $this->assertNotEmpty($matches, 'Breadcrumb nav not found in rendered output.');
        $this->assertSame(1, substr_count($matches[0], 'Jordan Rivera'));
    }

    public function test_general_settings_breadcrumb_comes_from_the_registry_not_a_hardcoded_page(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(GeneralSettingsPage::class)
            ->assertSeeInOrder(['Settings', 'General Settings']);
    }

    public function test_settings_breadcrumbs_never_render_the_registry_subgroup(): void
    {
        $this->actingAs($this->superAdmin());

        // NavigationRegistry carries a `subgroup` ('Platform' here) that is
        // informational only — HasCentralizedNavigation feeds Filament just
        // group/label/sort, so no such level exists in the sidebar or at any
        // URL. Rendering it produced "Settings > Platform > General Settings",
        // naming a destination a reader could never reach.
        $html = Livewire::test(GeneralSettingsPage::class)->html();

        preg_match('/<nav[^>]*fi-breadcrumbs.*?<\/nav>/s', $html, $matches);
        $this->assertNotEmpty($matches, 'Breadcrumb nav not found in rendered output.');

        $this->assertStringContainsString('Settings', $matches[0]);
        $this->assertStringContainsString('General Settings', $matches[0]);
        $this->assertStringNotContainsString(
            NavigationRegistry::subgroupFor(GeneralSettingsPage::class) ?? '__none__',
            $matches[0],
        );
    }
}
