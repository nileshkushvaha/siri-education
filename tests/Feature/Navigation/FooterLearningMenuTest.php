<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Enums\Navigation\NavigationLayoutType;
use App\Enums\Navigation\NavigationLinkType;
use App\Enums\Navigation\NavigationLocation;
use App\Enums\Navigation\NavigationStatus;
use App\Livewire\Frontend\Layout\SiteFooter;
use App\Livewire\Frontend\Layout\SiteHeader;
use App\Models\NavigationItem;
use App\Models\NavigationMenu;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The footer's "Learning" column, driven by the navigation location that
 * used to be the unused "Admin Menu".
 */
class FooterLearningMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function learningMenu(): NavigationMenu
    {
        return NavigationMenu::factory()->create([
            'name' => 'Footer Learning',
            'slug' => 'footer-learning',
            'location' => NavigationLocation::FooterLearning->value,
            'layout_type' => NavigationLayoutType::Standard->value,
            'status' => NavigationStatus::Published->value,
        ]);
    }

    private function menuAt(NavigationLocation $location, string $name, string $slug): NavigationMenu
    {
        return NavigationMenu::factory()->create([
            'name' => $name,
            'slug' => $slug,
            'location' => $location->value,
            'layout_type' => NavigationLayoutType::Standard->value,
            'status' => NavigationStatus::Published->value,
        ]);
    }

    private function item(NavigationMenu $menu, string $label, string $url): NavigationItem
    {
        return NavigationItem::factory()->create([
            'navigation_id' => $menu->id,
            'label' => $label,
            'link_type' => NavigationLinkType::Url->value,
            'url' => $url,
            'is_active' => true,
        ]);
    }

    // ── The location itself ──────────────────────────────────────────────

    public function test_the_retired_admin_menu_location_no_longer_exists(): void
    {
        $this->assertNull(NavigationLocation::tryFrom('admin_menu'));
        $this->assertSame(NavigationLocation::FooterLearning, NavigationLocation::from('footer_learning'));
        $this->assertSame('Footer Learning', NavigationLocation::FooterLearning->label());
    }

    // ── Rendering ────────────────────────────────────────────────────────

    public function test_learning_column_renders_the_assigned_menu(): void
    {
        $menu = $this->learningMenu();
        $this->item($menu, 'Study skills guide', 'https://example.com/study-skills');
        $this->item($menu, 'Exam revision tips', 'https://example.com/revision');

        Livewire::test(SiteFooter::class, ['appName' => 'SIRI Education'])
            ->assertSee('Study skills guide')
            ->assertSee('Exam revision tips');
    }

    public function test_learning_column_keeps_its_previous_content_when_no_menu_is_published(): void
    {
        // Adding the location must never blank the column for a site that
        // has not built the menu yet.
        Livewire::test(SiteFooter::class, ['appName' => 'SIRI Education'])
            ->assertSee('Learning')
            ->assertSee('Find an instructor');
    }

    public function test_a_draft_menu_does_not_render(): void
    {
        $menu = $this->learningMenu();
        $menu->update(['status' => NavigationStatus::Draft->value]);
        $this->item($menu, 'Unpublished link', 'https://example.com/hidden');

        Livewire::test(SiteFooter::class, ['appName' => 'SIRI Education'])
            ->assertDontSee('Unpublished link')
            ->assertSee('Find an instructor');
    }

    public function test_the_explore_column_is_unaffected(): void
    {
        $explore = NavigationMenu::factory()->create([
            'name' => 'Footer Explore',
            'slug' => 'footer-explore',
            'location' => NavigationLocation::Footer->value,
            'layout_type' => NavigationLayoutType::Standard->value,
            'status' => NavigationStatus::Published->value,
        ]);
        $this->item($explore, 'Explore link', 'https://example.com/explore');

        $learning = $this->learningMenu();
        $this->item($learning, 'Learning link', 'https://example.com/learning');

        Livewire::test(SiteFooter::class, ['appName' => 'SIRI Education'])
            ->assertSee('Explore link')
            ->assertSee('Learning link');
    }

    // ── Header fallback ──────────────────────────────────────────────────

    public function test_an_empty_mobile_menu_falls_back_to_the_header_menu(): void
    {
        // A published-but-empty mobile menu resolves to a tree with zero
        // nodes, which is not null, so a ?? fallback would keep it and
        // leave mobile visitors with no navigation at all.
        //
        // Asserted on the resolved tree rather than rendered HTML: the
        // mobile panel is only in the DOM once opened, and the desktop
        // nav prints the same header links, so an HTML assertion here
        // passes whether or not the fallback works.
        $header = $this->menuAt(NavigationLocation::Header, 'Header Navigation', 'header-navigation');
        $this->item($header, 'Find instructors', 'https://example.com/instructors');

        $this->menuAt(NavigationLocation::Mobile, 'Mobile Navigation', 'mobile');

        $tree = Livewire::test(SiteHeader::class, ['appName' => 'SIRI Education'])
            ->instance()
            ->mobileNavigation();

        $this->assertNotNull($tree);
        $this->assertFalse($tree->isEmpty(), 'Mobile navigation fell back to an empty menu.');
        $this->assertSame('Find instructors', $tree->nodes[0]->label);
    }

    public function test_a_populated_mobile_menu_still_wins(): void
    {
        $header = $this->menuAt(NavigationLocation::Header, 'Header Navigation', 'header-navigation');
        $this->item($header, 'Header only link', 'https://example.com/header');

        $mobile = $this->menuAt(NavigationLocation::Mobile, 'Mobile Navigation', 'mobile');
        $this->item($mobile, 'Mobile only link', 'https://example.com/mobile');

        $tree = Livewire::test(SiteHeader::class, ['appName' => 'SIRI Education'])
            ->instance()
            ->mobileNavigation();

        $this->assertSame('Mobile only link', $tree->nodes[0]->label);
    }

    // ── Seeder safety ────────────────────────────────────────────────────

    public function test_seeder_creates_the_footer_learning_menu(): void
    {
        $this->seed(NavigationSeeder::class);

        $menu = NavigationMenu::query()
            ->where('location', NavigationLocation::FooterLearning->value)
            ->firstOrFail();

        $this->assertSame('Footer Learning', $menu->name);
        $this->assertSame('footer-learning', $menu->slug);
    }

    public function test_seeder_never_adds_a_second_menu_to_a_location_that_already_has_one(): void
    {
        // findByLocation() resolves with an unordered ->first(), so a
        // duplicate published menu makes the rendered footer a coin toss.
        NavigationMenu::factory()->create([
            'name' => 'Admin-built footer',
            'slug' => 'my-custom-footer',
            'location' => NavigationLocation::Footer->value,
            'layout_type' => NavigationLayoutType::Standard->value,
            'status' => NavigationStatus::Published->value,
        ]);

        $this->seed(NavigationSeeder::class);
        $this->seed(NavigationSeeder::class);

        $this->assertSame(
            1,
            NavigationMenu::query()->where('location', NavigationLocation::Footer->value)->count(),
        );
        $this->assertSame(
            'Admin-built footer',
            NavigationMenu::query()->where('location', NavigationLocation::Footer->value)->value('name'),
        );
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(NavigationSeeder::class);
        $count = NavigationMenu::query()->count();

        $this->seed(NavigationSeeder::class);

        $this->assertSame($count, NavigationMenu::query()->count());
    }
}
