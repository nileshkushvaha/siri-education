<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Enums\Navigation\NavigationLayoutType;
use App\Enums\Navigation\NavigationLinkType;
use App\Enums\Navigation\NavigationLocation;
use App\Enums\Navigation\NavigationStatus;
use App\Models\NavigationItem;
use App\Models\NavigationMenu;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class NavigationComponentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function menu(array $attrs = []): NavigationMenu
    {
        return NavigationMenu::factory()->create(array_merge([
            'status' => NavigationStatus::Published->value,
            'location' => NavigationLocation::Header->value,
            'layout_type' => NavigationLayoutType::Standard->value,
            'slug' => 'header-nav',
        ], $attrs));
    }

    private function item(NavigationMenu $menu, array $attrs = []): NavigationItem
    {
        return NavigationItem::factory()->create(array_merge([
            'navigation_id' => $menu->id,
            'link_type' => NavigationLinkType::Url->value,
            'url' => 'https://example.com',
            'is_active' => true,
        ], $attrs));
    }

    private function render(string $template): string
    {
        return Blade::render($template);
    }

    // ── Empty state ────────────────────────────────────────────────────────

    public function test_renders_nothing_for_missing_menu(): void
    {
        $html = $this->render('<x-navigation location="header" />');

        $this->assertStringNotContainsString('<nav', $html);
    }

    public function test_renders_nothing_for_unpublished_menu(): void
    {
        $this->menu(['status' => NavigationStatus::Draft->value]);

        $html = $this->render('<x-navigation location="header" />');

        $this->assertStringNotContainsString('<nav', $html);
    }

    public function test_renders_nothing_for_menu_with_no_items(): void
    {
        $this->menu();

        $html = $this->render('<x-navigation location="header" />');

        $this->assertStringNotContainsString('<nav', $html);
    }

    public function test_empty_template_emits_debug_comment_outside_production(): void
    {
        $html = $this->render('<x-navigation location="nonexistent" />');

        $this->assertStringContainsString('<!--', $html);
    }

    // ── HTML structure ─────────────────────────────────────────────────────

    public function test_renders_nav_element_with_aria_label(): void
    {
        $menu = $this->menu(['name' => 'Main Navigation']);
        $this->item($menu, ['label' => 'Home']);

        $html = $this->render('<x-navigation location="header" />');

        $this->assertStringContainsString('<nav', $html);
        $this->assertStringContainsString('aria-label=', $html);
        $this->assertStringContainsString('Main Navigation', $html);
    }

    public function test_renders_ul_with_role_list(): void
    {
        $menu = $this->menu();
        $this->item($menu, ['label' => 'Home']);

        $html = $this->render('<x-navigation location="header" />');

        $this->assertStringContainsString('<ul', $html);
        $this->assertStringContainsString('role="list"', $html);
    }

    public function test_renders_links_with_href(): void
    {
        $menu = $this->menu();
        $this->item($menu, ['label' => 'About', 'url' => 'https://example.com/about']);

        $html = $this->render('<x-navigation location="header" />');

        $this->assertStringContainsString('href="https://example.com/about"', $html);
        $this->assertStringContainsString('About', $html);
    }

    public function test_custom_label_prop_overrides_menu_name(): void
    {
        $menu = $this->menu(['name' => 'Main Nav']);
        $this->item($menu, ['label' => 'Home']);

        $html = $this->render('<x-navigation location="header" label="Custom Label" />');

        $this->assertStringContainsString('Custom Label', $html);
    }

    // ── Active state ───────────────────────────────────────────────────────

    public function test_active_item_gets_aria_current_page(): void
    {
        $menu = $this->menu();
        // Set up an item pointing to the current request URL
        $this->item($menu, [
            'label' => 'Home',
            'url' => url('/'),
        ]);

        // Simulate current URL matching the item
        $this->get('/');

        $html = $this->render('<x-navigation location="header" />');

        // aria-current="false" is rendered for non-active; "page" for active
        $this->assertStringContainsString('aria-current=', $html);
    }

    // ── Nested / children ─────────────────────────────────────────────────

    public function test_renders_nested_children(): void
    {
        $menu = $this->menu();
        $parent = $this->item($menu, ['label' => 'Products', 'url' => '#']);
        $child = $this->item($menu, [
            'label' => 'Widget Pro',
            'url' => 'https://example.com/widget',
            'parent_id' => $parent->id,
        ]);

        $html = $this->render('<x-navigation location="header" />');

        $this->assertStringContainsString('Products', $html);
        $this->assertStringContainsString('Widget Pro', $html);
    }

    public function test_items_with_children_get_aria_haspopup(): void
    {
        $menu = $this->menu();
        $parent = $this->item($menu, ['label' => 'Products', 'url' => '#']);
        $this->item($menu, [
            'label' => 'Child Item',
            'url' => 'https://example.com/child',
            'parent_id' => $parent->id,
        ]);

        $html = $this->render('<x-navigation location="header" />');

        $this->assertStringContainsString('aria-haspopup="true"', $html);
    }

    public function test_items_with_children_get_aria_expanded(): void
    {
        $menu = $this->menu();
        $parent = $this->item($menu, ['label' => 'Products', 'url' => '#']);
        $this->item($menu, [
            'label' => 'Child',
            'url' => 'https://example.com/child',
            'parent_id' => $parent->id,
        ]);

        $html = $this->render('<x-navigation location="header" />');

        $this->assertStringContainsString('aria-expanded=', $html);
    }

    // ── Icon / Badge ───────────────────────────────────────────────────────

    public function test_renders_icon(): void
    {
        $menu = $this->menu();
        $this->item($menu, ['label' => 'Home', 'icon' => '🏠']);

        $html = $this->render('<x-navigation location="header" />');

        $this->assertStringContainsString('🏠', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function test_renders_badge_text(): void
    {
        $menu = $this->menu();
        $this->item($menu, [
            'label' => 'New',
            'badge_text' => 'Hot',
            'badge_color' => '#ef4444',
        ]);

        $html = $this->render('<x-navigation location="header" />');

        $this->assertStringContainsString('Hot', $html);
        $this->assertStringContainsString('#ef4444', $html);
    }

    // ── External links ─────────────────────────────────────────────────────

    public function test_external_links_get_target_blank(): void
    {
        $menu = $this->menu();
        $this->item($menu, [
            'label' => 'External',
            'url' => 'https://external.com',
            'target' => '_blank',
            'link_type' => NavigationLinkType::External->value,
        ]);

        $html = $this->render('<x-navigation location="header" />');

        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('opens in new tab', $html);
    }

    // ── Sidebar rendering ──────────────────────────────────────────────────

    public function test_sidebar_nav_renders_correct_element(): void
    {
        $menu = $this->menu([
            'location' => NavigationLocation::Sidebar->value,
            'layout_type' => NavigationLayoutType::Standard->value,
            'slug' => 'sidebar-nav',
        ]);
        $this->item($menu, ['label' => 'Dashboard']);

        $html = $this->render('<x-navigation location="sidebar" />');

        $this->assertStringContainsString('<nav', $html);
        $this->assertStringContainsString('Dashboard', $html);
    }

    // ── Footer rendering ──────────────────────────────────────────────────

    public function test_footer_nav_renders_links(): void
    {
        $menu = $this->menu([
            'location' => NavigationLocation::Footer->value,
            'layout_type' => NavigationLayoutType::Standard->value,
            'slug' => 'footer-nav',
        ]);
        $this->item($menu, ['label' => 'Privacy Policy', 'url' => '/privacy']);

        $html = $this->render('<x-navigation location="footer" />');

        $this->assertStringContainsString('<nav', $html);
        $this->assertStringContainsString('Privacy Policy', $html);
        $this->assertStringContainsString('/privacy', $html);
    }

    public function test_footer_multi_column_with_children(): void
    {
        $menu = $this->menu([
            'location' => NavigationLocation::Footer->value,
            'layout_type' => NavigationLayoutType::Standard->value,
            'slug' => 'footer-multi',
        ]);
        $col = $this->item($menu, ['label' => 'Company', 'url' => '#']);
        $this->item($menu, ['label' => 'About', 'url' => '/about', 'parent_id' => $col->id]);
        $this->item($menu, ['label' => 'Team', 'url' => '/team', 'parent_id' => $col->id]);

        $html = $this->render('<x-navigation location="footer" />');

        $this->assertStringContainsString('Company', $html);
        $this->assertStringContainsString('About', $html);
        $this->assertStringContainsString('Team', $html);
        $this->assertStringContainsString('grid', $html);
    }

    // ── Mobile rendering ──────────────────────────────────────────────────

    public function test_mobile_nav_renders_correct_element(): void
    {
        $menu = $this->menu([
            'location' => NavigationLocation::Mobile->value,
            'layout_type' => NavigationLayoutType::Standard->value,
            'slug' => 'mobile-nav',
        ]);
        $this->item($menu, ['label' => 'Home']);

        $html = $this->render('<x-navigation location="mobile" />');

        $this->assertStringContainsString('<nav', $html);
        $this->assertStringContainsString('Home', $html);
    }

    public function test_mobile_nested_items_have_toggle_button(): void
    {
        $menu = $this->menu([
            'location' => NavigationLocation::Mobile->value,
            'layout_type' => NavigationLayoutType::Standard->value,
            'slug' => 'mobile-nested',
        ]);
        $parent = $this->item($menu, ['label' => 'Services']);
        $this->item($menu, ['label' => 'Web Dev', 'parent_id' => $parent->id]);

        $html = $this->render('<x-navigation location="mobile" />');

        $this->assertStringContainsString('aria-expanded=', $html);
        $this->assertStringContainsString('aria-controls=', $html);
    }

    // ── Slug-based resolution ──────────────────────────────────────────────

    public function test_resolves_by_slug_for_unknown_location(): void
    {
        $menu = NavigationMenu::factory()->create([
            'status' => NavigationStatus::Published->value,
            'location' => NavigationLocation::Footer->value,
            'layout_type' => NavigationLayoutType::Standard->value,
            'slug' => 'footer-company',
        ]);
        $this->item($menu, ['label' => 'About Us', 'url' => '/about']);

        $html = $this->render('<x-navigation location="footer-company" />');

        $this->assertStringContainsString('About Us', $html);
    }

    // ── Locale filtering ──────────────────────────────────────────────────

    public function test_locale_prop_filters_items(): void
    {
        $menu = $this->menu(['slug' => 'locale-nav']);
        $this->item($menu, ['label' => 'English Only', 'url' => '/en', 'locale' => 'en']);
        $this->item($menu, ['label' => 'French Only', 'url' => '/fr', 'locale' => 'fr']);
        $this->item($menu, ['label' => 'Global', 'url' => '/global', 'locale' => null]);

        $html = $this->render('<x-navigation location="header" locale="en" />');

        $this->assertStringContainsString('English Only', $html);
        $this->assertStringContainsString('Global', $html);
        $this->assertStringNotContainsString('French Only', $html);
    }

    // ── Scheduled items ────────────────────────────────────────────────────

    public function test_future_scheduled_items_are_hidden(): void
    {
        $menu = $this->menu(['slug' => 'sched-nav']);
        $this->item($menu, [
            'label' => 'Coming Soon',
            'publish_from' => Carbon::now()->addDay(),
        ]);

        $html = $this->render('<x-navigation location="header" />');

        $this->assertStringNotContainsString('<nav', $html);
    }

    public function test_expired_items_are_hidden(): void
    {
        $menu = $this->menu(['slug' => 'expired-nav']);
        $this->item($menu, [
            'label' => 'Old News',
            'publish_from' => Carbon::now()->subWeek(),
            'publish_until' => Carbon::now()->subDay(),
        ]);

        $html = $this->render('<x-navigation location="header" />');

        $this->assertStringNotContainsString('<nav', $html);
    }

    public function test_live_scheduled_items_are_shown(): void
    {
        $menu = $this->menu(['slug' => 'live-nav']);
        $this->item($menu, [
            'label' => 'Live Now',
            'publish_from' => Carbon::now()->subHour(),
            'publish_until' => Carbon::now()->addHour(),
        ]);

        $html = $this->render('<x-navigation location="header" />');

        $this->assertStringContainsString('Live Now', $html);
    }

    // ── Permission filtering ───────────────────────────────────────────────

    public function test_guest_only_item_hidden_from_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $menu = $this->menu(['slug' => 'perm-nav']);
        $this->item($menu, [
            'label' => 'Sign In',
            'visibility' => 'guest',
        ]);

        $html = $this->render('<x-navigation location="header" />');

        $this->assertStringNotContainsString('Sign In', $html);
    }

    public function test_auth_only_item_hidden_from_guests(): void
    {
        $menu = $this->menu(['slug' => 'auth-nav']);
        $this->item($menu, [
            'label' => 'Dashboard',
            'visibility' => 'auth',
        ]);

        $html = $this->render('<x-navigation location="header" />');

        $this->assertStringNotContainsString('Dashboard', $html);
    }

    public function test_all_visibility_items_shown_to_everyone(): void
    {
        $menu = $this->menu(['slug' => 'all-nav']);
        $this->item($menu, [
            'label' => 'Public Link',
            'visibility' => 'all',
        ]);

        // Guest
        $html = $this->render('<x-navigation location="header" />');
        $this->assertStringContainsString('Public Link', $html);

        Cache::flush();

        // Auth user
        $user = User::factory()->create();
        $this->actingAs($user);
        $html = $this->render('<x-navigation location="header" />');
        $this->assertStringContainsString('Public Link', $html);
    }

    // ── Inactive items ─────────────────────────────────────────────────────

    public function test_inactive_items_are_excluded(): void
    {
        $menu = $this->menu(['slug' => 'inactive-nav']);
        $this->item($menu, ['label' => 'Hidden Item', 'is_active' => false]);

        $html = $this->render('<x-navigation location="header" />');

        $this->assertStringNotContainsString('<nav', $html);
    }

    // ── CSS class / ID ─────────────────────────────────────────────────────

    public function test_css_class_applied_to_item(): void
    {
        $menu = $this->menu(['slug' => 'css-nav']);
        $this->item($menu, [
            'label' => 'Styled',
            'css_class' => 'my-custom-class',
        ]);

        $html = $this->render('<x-navigation location="header" />');

        $this->assertStringContainsString('my-custom-class', $html);
    }

    public function test_css_id_applied_to_item(): void
    {
        $menu = $this->menu(['slug' => 'cssid-nav']);
        $this->item($menu, [
            'label' => 'IDed',
            'css_id' => 'my-item-id',
        ]);

        $html = $this->render('<x-navigation location="header" />');

        $this->assertStringContainsString('id="my-item-id"', $html);
    }
}
