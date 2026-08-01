<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Enums\Navigation\NavigationLayoutType;
use App\Enums\Navigation\NavigationLinkType;
use App\Enums\Navigation\NavigationLocation;
use App\Enums\Navigation\NavigationStatus;
use App\Models\NavigationItem;
use App\Models\NavigationMenu;
use App\Navigation\DTOs\NavigationItemData;
use App\Navigation\Services\NavigationItemService;
use App\Navigation\Services\NavigationManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Tests\TestCase;

class PublishWindowSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private NavigationItemService $service;

    private NavigationManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->service = app(NavigationItemService::class);
        $this->manager = app(NavigationManager::class);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function menu(array $attrs = []): NavigationMenu
    {
        return NavigationMenu::factory()->create(array_merge([
            'status' => NavigationStatus::Published->value,
            'location' => NavigationLocation::Header->value,
            'layout_type' => NavigationLayoutType::Standard->value,
        ], $attrs));
    }

    private function makeData(array $overrides = []): NavigationItemData
    {
        return new NavigationItemData(
            label: $overrides['label'] ?? 'Test Item',
            linkType: $overrides['linkType'] ?? NavigationLinkType::Url,
            url: $overrides['url'] ?? 'https://example.com',
            publishFrom: $overrides['publishFrom'] ?? null,
            publishUntil: $overrides['publishUntil'] ?? null,
            locale: $overrides['locale'] ?? null,
        );
    }

    // ── PublishWindow::from() parity with always() ─────────────────────────

    public function test_item_with_null_publish_window_is_always_visible(): void
    {
        $menu = $this->menu();
        $item = $this->service->create($menu, $this->makeData());

        $this->assertNull($item->publish_from);
        $this->assertNull($item->publish_until);
    }

    // ── Validation ─────────────────────────────────────────────────────────

    public function test_create_rejects_publish_until_before_publish_from(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('publish_until must be after publish_from');

        $menu = $this->menu();
        $this->service->create($menu, $this->makeData([
            'publishFrom' => Carbon::now()->addHour(),
            'publishUntil' => Carbon::now()->subHour(),
        ]));
    }

    public function test_create_rejects_publish_until_equal_to_publish_from(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $ts = Carbon::now()->addHour();
        $menu = $this->menu();
        $this->service->create($menu, $this->makeData([
            'publishFrom' => $ts,
            'publishUntil' => $ts->copy(),
        ]));
    }

    public function test_update_rejects_invalid_publish_window(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $menu = $this->menu();
        $item = $this->service->create($menu, $this->makeData());

        $this->service->update($item, $this->makeData([
            'publishFrom' => Carbon::now()->addDay(),
            'publishUntil' => Carbon::now(),
        ]));
    }

    public function test_create_rejects_invalid_locale(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('locale must be a valid language tag');

        $menu = $this->menu();
        $this->service->create($menu, $this->makeData(['locale' => 'english']));
    }

    public function test_create_rejects_locale_with_wrong_case(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $menu = $this->menu();
        $this->service->create($menu, $this->makeData(['locale' => 'EN']));
    }

    public function test_valid_locales_are_accepted(): void
    {
        $menu = $this->menu();
        $locales = ['en', 'fr', 'en-US', 'pt-BR', 'zh-CN', 'de-AT'];

        foreach ($locales as $locale) {
            $item = $this->service->create($menu, $this->makeData(['locale' => $locale]));
            $this->assertSame($locale, $item->locale, "Failed for locale: {$locale}");
        }
    }

    public function test_valid_publish_window_is_persisted(): void
    {
        $from = Carbon::now()->addHour();
        $until = Carbon::now()->addDays(7);
        $menu = $this->menu();

        $item = $this->service->create($menu, $this->makeData([
            'publishFrom' => $from,
            'publishUntil' => $until,
        ]));

        $item->refresh();
        $this->assertTrue(abs($item->publish_from->timestamp - $from->timestamp) < 2);
        $this->assertTrue(abs($item->publish_until->timestamp - $until->timestamp) < 2);
    }

    // ── NavigationManager: publish window filtering ────────────────────────

    public function test_item_before_publish_from_is_excluded_from_tree(): void
    {
        $menu = $this->menu();
        NavigationItem::factory()->create([
            'navigation_id' => $menu->id,
            'label' => 'Future Item',
            'link_type' => NavigationLinkType::Url->value,
            'url' => 'https://example.com',
            'publish_from' => Carbon::now()->addHour(),
            'publish_until' => null,
            'is_active' => true,
        ]);

        $tree = $this->manager->forLocation(NavigationLocation::Header);

        $this->assertNotNull($tree);
        $this->assertEmpty($tree->nodes, 'Item with future publish_from should not appear.');
    }

    public function test_item_after_publish_until_is_excluded_from_tree(): void
    {
        $menu = $this->menu();
        NavigationItem::factory()->create([
            'navigation_id' => $menu->id,
            'label' => 'Expired Item',
            'link_type' => NavigationLinkType::Url->value,
            'url' => 'https://example.com',
            'publish_from' => Carbon::now()->subDay(),
            'publish_until' => Carbon::now()->subHour(),
            'is_active' => true,
        ]);

        $tree = $this->manager->forLocation(NavigationLocation::Header);

        $this->assertNotNull($tree);
        $this->assertEmpty($tree->nodes, 'Expired item should not appear.');
    }

    public function test_item_within_publish_window_is_included(): void
    {
        $menu = $this->menu();
        NavigationItem::factory()->create([
            'navigation_id' => $menu->id,
            'label' => 'Active Scheduled',
            'link_type' => NavigationLinkType::Url->value,
            'url' => 'https://example.com',
            'publish_from' => Carbon::now()->subHour(),
            'publish_until' => Carbon::now()->addHour(),
            'is_active' => true,
        ]);

        $tree = $this->manager->forLocation(NavigationLocation::Header);

        $this->assertNotNull($tree);
        $this->assertCount(1, $tree->nodes, 'Item within publish window should appear.');
        $this->assertSame('Active Scheduled', $tree->nodes[0]->label);
    }

    public function test_item_with_only_publish_from_in_past_is_included(): void
    {
        $menu = $this->menu();
        NavigationItem::factory()->create([
            'navigation_id' => $menu->id,
            'label' => 'Started Item',
            'link_type' => NavigationLinkType::Url->value,
            'url' => 'https://example.com',
            'publish_from' => Carbon::now()->subDay(),
            'publish_until' => null,
            'is_active' => true,
        ]);

        $tree = $this->manager->forLocation(NavigationLocation::Header);

        $this->assertNotNull($tree);
        $this->assertCount(1, $tree->nodes);
    }

    public function test_item_with_only_publish_until_in_future_is_included(): void
    {
        $menu = $this->menu();
        NavigationItem::factory()->create([
            'navigation_id' => $menu->id,
            'label' => 'Has Until',
            'link_type' => NavigationLinkType::Url->value,
            'url' => 'https://example.com',
            'publish_from' => null,
            'publish_until' => Carbon::now()->addDay(),
            'is_active' => true,
        ]);

        $tree = $this->manager->forLocation(NavigationLocation::Header);

        $this->assertNotNull($tree);
        $this->assertCount(1, $tree->nodes);
    }

    // ── NavigationManager: locale filtering ───────────────────────────────

    public function test_item_without_locale_shown_for_any_locale(): void
    {
        $menu = $this->menu();
        NavigationItem::factory()->create([
            'navigation_id' => $menu->id,
            'label' => 'Global Item',
            'link_type' => NavigationLinkType::Url->value,
            'url' => 'https://example.com',
            'locale' => null,
            'is_active' => true,
        ]);

        $tree = $this->manager->forLocation(NavigationLocation::Header, 'en');

        $this->assertNotNull($tree);
        $this->assertCount(1, $tree->nodes, 'Item without locale should appear for any locale.');
    }

    public function test_item_with_matching_locale_is_shown(): void
    {
        $menu = $this->menu();
        NavigationItem::factory()->create([
            'navigation_id' => $menu->id,
            'label' => 'French Item',
            'link_type' => NavigationLinkType::Url->value,
            'url' => 'https://example.com',
            'locale' => 'fr',
            'is_active' => true,
        ]);

        $tree = $this->manager->forLocation(NavigationLocation::Header, 'fr');

        $this->assertNotNull($tree);
        $this->assertCount(1, $tree->nodes, 'Item with matching locale should appear.');
    }

    public function test_item_with_non_matching_locale_is_hidden(): void
    {
        $menu = $this->menu();
        NavigationItem::factory()->create([
            'navigation_id' => $menu->id,
            'label' => 'French Only',
            'link_type' => NavigationLinkType::Url->value,
            'url' => 'https://example.com',
            'locale' => 'fr',
            'is_active' => true,
        ]);

        $tree = $this->manager->forLocation(NavigationLocation::Header, 'en');

        $this->assertNotNull($tree);
        $this->assertEmpty($tree->nodes, 'Item with non-matching locale should be hidden.');
    }

    public function test_mixed_locale_items_filtered_correctly(): void
    {
        $menu = $this->menu();
        NavigationItem::factory()->create([
            'navigation_id' => $menu->id,
            'label' => 'Global',
            'link_type' => NavigationLinkType::Url->value,
            'url' => 'https://example.com/global',
            'locale' => null,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        NavigationItem::factory()->create([
            'navigation_id' => $menu->id,
            'label' => 'English Only',
            'link_type' => NavigationLinkType::Url->value,
            'url' => 'https://example.com/en',
            'locale' => 'en',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        NavigationItem::factory()->create([
            'navigation_id' => $menu->id,
            'label' => 'French Only',
            'link_type' => NavigationLinkType::Url->value,
            'url' => 'https://example.com/fr',
            'locale' => 'fr',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $en = $this->manager->forLocation(NavigationLocation::Header, 'en');
        $fr = $this->manager->forLocation(NavigationLocation::Header, 'fr');
        $none = $this->manager->forLocation(NavigationLocation::Header);

        $this->assertNotNull($en);
        $this->assertNotNull($fr);
        $this->assertNotNull($none);

        $enLabels = array_column($en->nodes, 'label');
        $this->assertContains('Global', $enLabels);
        $this->assertContains('English Only', $enLabels);
        $this->assertNotContains('French Only', $enLabels);

        $frLabels = array_column($fr->nodes, 'label');
        $this->assertContains('Global', $frLabels);
        $this->assertContains('French Only', $frLabels);
        $this->assertNotContains('English Only', $frLabels);

        // No locale filter: all items returned
        $this->assertCount(3, $none->nodes);
    }

    // ── Tree array includes scheduling fields ──────────────────────────────

    public function test_tree_array_includes_locale_and_scheduling_fields(): void
    {
        $menu = $this->menu();
        $from = Carbon::now()->addHour();
        $item = $this->service->create($menu, $this->makeData([
            'locale' => 'en',
            'publishFrom' => $from,
            'publishUntil' => null,
        ]));

        $tree = $this->service->getTreeArray($menu);

        $this->assertNotEmpty($tree);
        $node = $tree[0];
        $this->assertArrayHasKey('locale', $node);
        $this->assertArrayHasKey('publish_from', $node);
        $this->assertArrayHasKey('publish_until', $node);
        $this->assertSame('en', $node['locale']);
        $this->assertNotNull($node['publish_from']);
        $this->assertNull($node['publish_until']);
    }

    // ── Cache invalidation with scheduling changes ─────────────────────────

    public function test_cache_is_invalidated_when_publish_window_updated(): void
    {
        $menu = $this->menu();
        NavigationItem::factory()->create([
            'navigation_id' => $menu->id,
            'label' => 'Item',
            'link_type' => NavigationLinkType::Url->value,
            'url' => 'https://example.com',
            'is_active' => true,
        ]);

        $before = $this->manager->forLocation(NavigationLocation::Header);
        $this->assertNotNull($before);
        $this->assertCount(1, $before->nodes);

        // Update the item to be future-scheduled, which should invalidate cache
        $item = NavigationItem::where('navigation_id', $menu->id)->first();
        $data = new NavigationItemData(
            label: $item->label,
            linkType: $item->link_type,
            url: $item->url,
            publishFrom: Carbon::now()->addHour(),
            publishUntil: null,
        );
        $this->service->update($item, $data);

        // Cache was invalidated, manager should now re-render and exclude item
        $after = $this->manager->forLocation(NavigationLocation::Header);
        $this->assertNotNull($after);
        $this->assertEmpty($after->nodes, 'Item should be excluded after publish_from moved to future.');
    }

    // ── Duplicate copies scheduling fields ────────────────────────────────

    public function test_duplicate_copies_scheduling_fields(): void
    {
        $menu = $this->menu();
        $from = Carbon::now()->subHour();
        $until = Carbon::now()->addDay();

        $item = $this->service->create($menu, $this->makeData([
            'locale' => 'en',
            'publishFrom' => $from,
            'publishUntil' => $until,
        ]));

        $copy = $this->service->duplicate($item);

        $copy->refresh();
        $this->assertSame('en', $copy->locale);
        $this->assertNotNull($copy->publish_from);
        $this->assertNotNull($copy->publish_until);
    }
}
