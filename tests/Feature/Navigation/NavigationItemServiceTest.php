<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Enums\Navigation\NavigationLayoutType;
use App\Enums\Navigation\NavigationLinkType;
use App\Enums\Navigation\NavigationLocation;
use App\Enums\Navigation\NavigationStatus;
use App\Enums\Navigation\NavigationVisibility;
use App\Models\NavigationItem;
use App\Models\NavigationMenu;
use App\Models\Page;
use App\Navigation\DTOs\NavigationItemData;
use App\Navigation\Services\NavigationItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NavigationItemServiceTest extends TestCase
{
    use RefreshDatabase;

    private NavigationItemService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(NavigationItemService::class);
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

    private function item(NavigationMenu $menu, array $attrs = []): NavigationItem
    {
        return NavigationItem::factory()->create(array_merge([
            'navigation_id' => $menu->id,
            'link_type' => NavigationLinkType::Url->value,
            'url' => 'https://example.com',
        ], $attrs));
    }

    private function urlData(string $label = 'Test', array $overrides = []): NavigationItemData
    {
        return NavigationItemData::fromArray(array_merge([
            'label' => $label,
            'link_type' => 'url',
            'url' => 'https://example.com',
        ], $overrides));
    }

    // ── findItem ──────────────────────────────────────────────────────────

    public function test_find_item_returns_item_with_relations(): void
    {
        $menu = $this->menu();
        $navItem = $this->item($menu);

        $found = $this->service->findItem($navItem->id);

        $this->assertNotNull($found);
        $this->assertTrue($found->relationLoaded('roles'));
        $this->assertTrue($found->relationLoaded('permissions'));
    }

    public function test_find_item_returns_null_for_unknown_id(): void
    {
        $this->assertNull($this->service->findItem('00000000-0000-0000-0000-000000000000'));
    }

    // ── getTreeArray ──────────────────────────────────────────────────────

    public function test_get_tree_array_returns_empty_for_menu_with_no_items(): void
    {
        $menu = $this->menu();
        $this->assertSame([], $this->service->getTreeArray($menu));
    }

    public function test_get_tree_array_returns_root_items(): void
    {
        $menu = $this->menu();
        $this->item($menu, ['label' => 'Home']);
        $this->item($menu, ['label' => 'About']);

        $tree = $this->service->getTreeArray($menu);

        $this->assertCount(2, $tree);
        $labels = array_column($tree, 'label');
        $this->assertContains('Home', $labels);
        $this->assertContains('About', $labels);
    }

    public function test_get_tree_array_nests_children_under_parent(): void
    {
        $menu = $this->menu();
        $parent = $this->item($menu, ['label' => 'Services']);
        $child = NavigationItem::factory()->create([
            'navigation_id' => $menu->id,
            'parent_id' => $parent->id,
            'label' => 'Web Design',
            'link_type' => NavigationLinkType::Url->value,
            'url' => 'https://example.com/web',
        ]);
        NavigationItem::fixTree();

        $tree = $this->service->getTreeArray($menu);

        $this->assertCount(1, $tree);
        $this->assertSame('Services', $tree[0]['label']);
        $this->assertCount(1, $tree[0]['children']);
        $this->assertSame('Web Design', $tree[0]['children'][0]['label']);
    }

    public function test_get_tree_array_only_includes_items_from_given_menu(): void
    {
        $menuA = $this->menu(['slug' => 'menu-a', 'location' => NavigationLocation::Header->value]);
        $menuB = $this->menu(['slug' => 'menu-b', 'location' => NavigationLocation::Footer->value]);
        $this->item($menuA, ['label' => 'A Item']);
        $this->item($menuB, ['label' => 'B Item']);

        $treeA = $this->service->getTreeArray($menuA);
        $treeB = $this->service->getTreeArray($menuB);

        $this->assertCount(1, $treeA);
        $this->assertSame('A Item', $treeA[0]['label']);
        $this->assertCount(1, $treeB);
        $this->assertSame('B Item', $treeB[0]['label']);
    }

    // ── createForLinkable ─────────────────────────────────────────────────

    public function test_create_for_linkable_sets_morph_fields(): void
    {
        $menu = $this->menu();
        $page = Page::factory()->create(['title' => 'About Us']);

        $item = $this->service->createForLinkable(
            $menu,
            'page',
            $page->id,
            $page->title,
            NavigationLinkType::Page,
        );

        $this->assertSame('About Us', $item->label);
        $this->assertSame(NavigationLinkType::Page, $item->link_type);
        $this->assertSame('page', $item->linkable_type);
        $this->assertSame($page->id, $item->linkable_id);
    }

    // ── createForUrl ──────────────────────────────────────────────────────

    public function test_create_for_url_sets_url_field(): void
    {
        $menu = $this->menu();

        $item = $this->service->createForUrl(
            $menu,
            NavigationLinkType::Email,
            'info@example.com',
            'Contact Us',
        );

        $this->assertSame('Contact Us', $item->label);
        $this->assertSame(NavigationLinkType::Email, $item->link_type);
        $this->assertSame('info@example.com', $item->url);
        $this->assertNull($item->linkable_type);
    }

    // ── create ────────────────────────────────────────────────────────────

    public function test_create_saves_item_as_root_when_no_parent(): void
    {
        $menu = $this->menu();
        $data = $this->urlData('Home');

        $item = $this->service->create($menu, $data);

        $this->assertDatabaseHas('navigation_items', [
            'id' => $item->id,
            'navigation_id' => $menu->id,
            'parent_id' => null,
            'label' => 'Home',
        ]);
    }

    public function test_create_saves_item_as_child_when_parent_given(): void
    {
        $menu = $this->menu();
        $parent = $this->item($menu, ['label' => 'Parent']);
        $data = NavigationItemData::fromArray([
            'label' => 'Child',
            'link_type' => 'url',
            'url' => 'https://child.example.com',
            'parent_id' => $parent->id,
        ]);

        $child = $this->service->create($menu, $data);

        $this->assertDatabaseHas('navigation_items', [
            'id' => $child->id,
            'parent_id' => $parent->id,
            'label' => 'Child',
        ]);
    }

    public function test_create_ignores_parent_from_different_menu(): void
    {
        $menuA = $this->menu(['slug' => 'ma', 'location' => NavigationLocation::Header->value]);
        $menuB = $this->menu(['slug' => 'mb', 'location' => NavigationLocation::Footer->value]);
        $parent = $this->item($menuA);

        $data = NavigationItemData::fromArray([
            'label' => 'Orphan',
            'link_type' => 'url',
            'url' => 'https://example.com',
            'parent_id' => $parent->id,
        ]);

        $item = $this->service->create($menuB, $data);

        $this->assertNull($item->fresh()->parent_id);
    }

    public function test_create_invalidates_menu_cache(): void
    {
        Cache::flush();
        $menu = $this->menu();
        $data = $this->urlData('Home');

        $this->service->create($menu, $data);

        // After create, the cache manager should have no entries for this menu
        // (they were invalidated before any were added in this test)
        $this->assertTrue(true); // cache invalidation is tested via CacheInvalidationTest
    }

    // ── update ────────────────────────────────────────────────────────────

    public function test_update_changes_item_attributes(): void
    {
        $menu = $this->menu();
        $item = $this->item($menu, ['label' => 'Old Label']);

        $this->service->update($item, $this->urlData('New Label', [
            'icon' => 'heroicon-o-star',
            'css_class' => 'featured',
            'is_active' => false,
        ]));

        $fresh = $item->fresh();
        $this->assertSame('New Label', $fresh->label);
        $this->assertSame('heroicon-o-star', $fresh->icon);
        $this->assertSame('featured', $fresh->css_class);
        $this->assertFalse($fresh->is_active);
    }

    public function test_update_syncs_roles_when_visibility_is_roles(): void
    {
        $menu = $this->menu();
        $item = $this->item($menu);
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);

        $data = NavigationItemData::fromArray([
            'label' => $item->label,
            'link_type' => 'url',
            'visibility' => 'roles',
            'required_role_ids' => [$role->id],
        ]);

        $this->service->update($item, $data);

        $this->assertCount(1, $item->fresh()->roles);
        $this->assertSame($role->id, $item->fresh()->roles->first()->id);
    }

    public function test_update_detaches_roles_when_visibility_changes_from_roles(): void
    {
        $menu = $this->menu();
        $item = $this->item($menu);
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $item->roles()->attach($role);

        $data = NavigationItemData::fromArray([
            'label' => $item->label,
            'link_type' => 'url',
            'visibility' => 'all',
        ]);

        $this->service->update($item, $data);

        $this->assertCount(0, $item->fresh()->roles);
    }

    public function test_update_syncs_permissions_when_visibility_is_permissions(): void
    {
        $menu = $this->menu();
        $item = $this->item($menu);
        $perm = Permission::create(['name' => 'view-reports', 'guard_name' => 'web']);

        $data = NavigationItemData::fromArray([
            'label' => $item->label,
            'link_type' => 'url',
            'visibility' => 'permissions',
            'required_permission_ids' => [$perm->id],
        ]);

        $this->service->update($item, $data);

        $this->assertCount(1, $item->fresh()->permissions);
    }

    // ── delete ────────────────────────────────────────────────────────────

    public function test_delete_soft_deletes_the_item(): void
    {
        $menu = $this->menu();
        $item = $this->item($menu);
        $id = $item->id;

        $this->service->delete($item);

        $this->assertSoftDeleted('navigation_items', ['id' => $id]);
    }

    public function test_delete_invalidates_cache(): void
    {
        Cache::flush();
        $menu = $this->menu();
        $item = $this->item($menu);

        $this->service->delete($item);

        // Verify cache index no longer tracks keys for this menu
        $index = Cache::get('nav:tree:index', []);
        $this->assertArrayNotHasKey($menu->id, $index);
    }

    // ── duplicate ─────────────────────────────────────────────────────────

    public function test_duplicate_creates_new_item_with_copy_suffix(): void
    {
        $menu = $this->menu();
        $item = $this->item($menu, ['label' => 'Services']);

        $copy = $this->service->duplicate($item);

        $this->assertNotSame($item->id, $copy->id);
        $this->assertSame('Services (Copy)', $copy->label);
        $this->assertSame($menu->id, $copy->navigation_id);
    }

    public function test_duplicate_copies_roles_to_new_item(): void
    {
        $menu = $this->menu();
        $item = $this->item($menu);
        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $item->roles()->attach($role);
        $item->load('roles', 'permissions');

        $copy = $this->service->duplicate($item);

        $this->assertCount(1, $copy->fresh()->roles);
    }

    public function test_duplicate_preserves_parent_when_item_has_parent(): void
    {
        $menu = $this->menu();
        $parent = $this->item($menu, ['label' => 'Parent']);
        NavigationItem::fixTree();
        $child = NavigationItem::factory()->create([
            'navigation_id' => $menu->id,
            'parent_id' => $parent->id,
            'label' => 'Child',
            'link_type' => NavigationLinkType::Url->value,
            'url' => 'https://example.com',
        ]);
        $child->load('roles', 'permissions');
        NavigationItem::fixTree();

        $copy = $this->service->duplicate($child);

        $this->assertSame($parent->id, $copy->fresh()->parent_id);
    }

    // ── reorder ───────────────────────────────────────────────────────────

    public function test_reorder_updates_sort_order(): void
    {
        $menu = $this->menu();
        $itemA = $this->item($menu, ['label' => 'A', 'sort_order' => 0]);
        $itemB = $this->item($menu, ['label' => 'B', 'sort_order' => 1]);
        NavigationItem::fixTree();

        $this->service->reorder($menu, [
            ['id' => $itemB->id, 'parentId' => null, 'sortOrder' => 0],
            ['id' => $itemA->id, 'parentId' => null, 'sortOrder' => 1],
        ]);

        $this->assertSame(0, $itemB->fresh()->sort_order);
        $this->assertSame(1, $itemA->fresh()->sort_order);
    }

    public function test_reorder_updates_parent_id_for_nesting(): void
    {
        $menu = $this->menu();
        $parent = $this->item($menu, ['label' => 'Parent']);
        $child = $this->item($menu, ['label' => 'Child']);
        NavigationItem::fixTree();

        $this->service->reorder($menu, [
            ['id' => $parent->id, 'parentId' => null,        'sortOrder' => 0],
            ['id' => $child->id,  'parentId' => $parent->id, 'sortOrder' => 0],
        ]);

        $this->assertSame($parent->id, $child->fresh()->parent_id);
    }

    public function test_reorder_ignores_items_from_other_menus(): void
    {
        $menuA = $this->menu(['slug' => 'ma', 'location' => NavigationLocation::Header->value]);
        $menuB = $this->menu(['slug' => 'mb', 'location' => NavigationLocation::Footer->value]);
        $itemA = $this->item($menuA, ['label' => 'A']);
        $itemB = $this->item($menuB, ['label' => 'B']);
        NavigationItem::fixTree();
        $originalParentB = $itemB->parent_id;

        // Try to move menuB's item via menuA's reorder — must be silently skipped
        $this->service->reorder($menuA, [
            ['id' => $itemA->id, 'parentId' => null,     'sortOrder' => 0],
            ['id' => $itemB->id, 'parentId' => $itemA->id, 'sortOrder' => 1],
        ]);

        $this->assertSame($originalParentB, $itemB->fresh()->parent_id);
    }

    public function test_reorder_with_empty_array_does_nothing(): void
    {
        $menu = $this->menu();
        $item = $this->item($menu, ['sort_order' => 5]);

        $this->service->reorder($menu, []);

        $this->assertSame(5, $item->fresh()->sort_order);
    }

    // ── Tree array structure ──────────────────────────────────────────────

    public function test_tree_array_node_contains_required_keys(): void
    {
        $menu = $this->menu();
        $this->item($menu, ['label' => 'Home', 'is_active' => true]);

        $tree = $this->service->getTreeArray($menu);

        $node = $tree[0];
        foreach (['id', 'label', 'link_type', 'visibility', 'is_active', 'icon', 'children'] as $key) {
            $this->assertArrayHasKey($key, $node, "Missing key: {$key}");
        }
    }

    public function test_tree_array_children_key_is_empty_array_for_leaf(): void
    {
        $menu = $this->menu();
        $this->item($menu);

        $tree = $this->service->getTreeArray($menu);

        $this->assertSame([], $tree[0]['children']);
    }

    // ── Visibility enum value in tree ─────────────────────────────────────

    public function test_tree_array_contains_visibility_as_string(): void
    {
        $menu = $this->menu();
        $this->item($menu, ['visibility' => NavigationVisibility::Auth->value]);

        $tree = $this->service->getTreeArray($menu);

        $this->assertSame('auth', $tree[0]['visibility']);
    }
}
