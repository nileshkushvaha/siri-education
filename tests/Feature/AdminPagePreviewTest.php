<?php

namespace Tests\Feature;

use App\Content\Models\ContentBlock;
use App\Enums\PageStatus;
use App\Enums\PageVisibility;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AdminPagePreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'pages.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'pages.list', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['pages.view', 'pages.list']);
    }

    /**
     * The existing cases below accept "200 or 302", which means a redirect
     * at the middleware layer counts as a pass and the controller body is
     * never entered. That is exactly how a fatal error inside it
     * (a $this->authorize() call on a base controller that no longer has
     * AuthorizesRequests) reached a browser while this suite stayed green.
     * This case therefore insists on a real 200 from a real render.
     */
    public function test_permitted_admin_actually_renders_the_preview(): void
    {
        $admin = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Permission::firstOrCreate(['name' => 'View:Page', 'guard_name' => 'web']);
        $admin->givePermissionTo('View:Page');

        $page = $this->createPageWithBlock(['slug' => 'preview-renders']);

        $this->actingAs($admin)
            ->get(route('admin.pages.preview', $page))
            ->assertOk()
            ->assertSee($page->title, false);
    }

    private function createPageWithBlock(array $pageData = [], array $blockData = []): Page
    {
        $page = Page::create(array_merge([
            'title' => 'Test Page',
            'slug' => 'test-page',
            'excerpt' => 'Test excerpt',
            'template' => 'default',
            'layout' => 'page',
            'status' => PageStatus::Published,
            'visibility' => PageVisibility::Public,
            'meta_title' => 'Test Meta',
            'meta_description' => 'Test Description',
            'robots' => 'index, follow',
        ], $pageData));

        ContentBlock::create(array_merge([
            'blockable_type' => 'page',
            'blockable_id' => $page->id,
            'block_type' => 'hero',
            'content' => json_encode(['heading' => 'Test']),
            'settings' => json_encode([]),
            'sort_order' => 1,
            'is_active' => true,
        ], $blockData));

        return $page->fresh();
    }

    public function test_preview_requires_authentication(): void
    {
        $page = $this->createPageWithBlock();
        $response = $this->get(route('admin.pages.preview', $page));

        $this->assertTrue($response->isRedirect() || $response->status() === 401);
    }

    public function test_authorized_user_can_preview_published_page(): void
    {
        $page = $this->createPageWithBlock();

        $response = $this->actingAs($this->user)
            ->get(route('admin.pages.preview', $page));

        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_authorized_user_can_preview_draft_page(): void
    {
        $page = $this->createPageWithBlock(['status' => PageStatus::Draft]);

        $response = $this->actingAs($this->user)
            ->get(route('admin.pages.preview', $page));

        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_authorized_user_can_preview_scheduled_page(): void
    {
        $page = $this->createPageWithBlock([
            'status' => PageStatus::Scheduled,
            'published_at' => now()->addDays(7),
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('admin.pages.preview', $page));

        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_authorized_user_can_preview_archived_page(): void
    {
        $page = $this->createPageWithBlock(['status' => PageStatus::Archived]);

        $response = $this->actingAs($this->user)
            ->get(route('admin.pages.preview', $page));

        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_preview_view_displays_page_information(): void
    {
        $page = $this->createPageWithBlock([
            'title' => 'About Us',
            'slug' => 'about-us',
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('admin.pages.preview', $page));

        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_preview_handles_page_with_multiple_blocks(): void
    {
        $page = Page::create([
            'title' => 'Multi Block',
            'slug' => 'multi-block',
            'excerpt' => 'Test',
            'template' => 'default',
            'layout' => 'page',
            'status' => PageStatus::Published,
            'visibility' => PageVisibility::Public,
            'meta_title' => 'Test',
            'meta_description' => 'Test',
            'robots' => 'index, follow',
        ]);

        ContentBlock::create([
            'blockable_type' => 'page',
            'blockable_id' => $page->id,
            'block_type' => 'hero',
            'content' => json_encode(['heading' => 'Hero']),
            'settings' => json_encode([]),
            'sort_order' => 1,
            'is_active' => true,
        ]);

        ContentBlock::create([
            'blockable_type' => 'page',
            'blockable_id' => $page->id,
            'block_type' => 'rich_text',
            'content' => json_encode(['content' => '<p>Rich text</p>']),
            'settings' => json_encode([]),
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('admin.pages.preview', $page->fresh()));

        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_preview_with_deleted_page_returns_404(): void
    {
        $page = $this->createPageWithBlock();
        $page->delete();

        $response = $this->actingAs($this->user)
            ->get(route('admin.pages.preview', $page));

        $response->assertNotFound();
    }

    public function test_preview_with_nonexistent_page_returns_404(): void
    {
        $this->actingAs($this->user)
            ->get('/admin/pages/999/preview')
            ->assertNotFound();
    }
}
