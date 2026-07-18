<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use App\Enums\PageStatus;
use App\Enums\PageVisibility;
use App\Models\Page;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FrontendIntegrationTest extends TestCase
{
    use RefreshDatabase;

    // ── Home ──────────────────────────────────────────────────────────────

    public function test_home_page_returns_200(): void
    {
        $this->get(route('home'))->assertStatus(200);
    }

    public function test_default_homepage_uses_the_public_cms_ready_composition(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-public-homepage', false)
            ->assertSee('Explore instructors')
            ->assertSee('Start learning in four clear steps')
            ->assertDontSee('10,000+ Active Students');
    }

    // ── CMS Pages ─────────────────────────────────────────────────────────

    public function test_published_public_page_is_accessible(): void
    {
        $page = Page::factory()->create([
            'status' => PageStatus::Published,
            'visibility' => PageVisibility::Public,
            'slug' => 'about-us',
            'title' => 'About Us',
        ]);

        $this->get(route('page.show', $page->slug))->assertOk();
    }

    public function test_draft_page_returns_404(): void
    {
        $page = Page::factory()->create([
            'status' => PageStatus::Draft,
            'slug' => 'hidden-draft',
        ]);

        $this->get(route('page.show', $page->slug))->assertNotFound();
    }

    public function test_private_page_returns_404_for_guests(): void
    {
        $page = Page::factory()->create([
            'status' => PageStatus::Published,
            'visibility' => PageVisibility::Private,
            'slug' => 'private-page',
        ]);

        $this->get(route('page.show', $page->slug))->assertNotFound();
    }

    // ── Blog Index ────────────────────────────────────────────────────────

    public function test_blog_index_returns_200(): void
    {
        $this->get(route('blog.index'))->assertOk();
    }

    public function test_blog_index_shows_published_posts(): void
    {
        $post = Post::factory()->published()->create(['title' => 'Test Article']);

        $this->get(route('blog.index'))->assertOk()->assertSee('Test Article');
    }

    public function test_blog_index_hides_draft_posts(): void
    {
        $post = Post::factory()->draft()->create(['title' => 'Secret Draft']);

        $this->get(route('blog.index'))->assertOk()->assertDontSee('Secret Draft');
    }

    // ── Blog Show ─────────────────────────────────────────────────────────

    public function test_published_post_is_accessible(): void
    {
        $post = Post::factory()->published()->create(['slug' => 'my-article', 'title' => 'My Article']);

        $this->get(route('blog.show', $post->slug))->assertOk();
    }

    public function test_draft_post_returns_404(): void
    {
        $post = Post::factory()->draft()->create(['slug' => 'draft-article']);

        $this->get(route('blog.show', $post->slug))->assertNotFound();
    }

    public function test_future_scheduled_post_returns_404(): void
    {
        $post = Post::factory()->scheduled()->create(['slug' => 'future-article']);

        $this->get(route('blog.show', $post->slug))->assertNotFound();
    }

    // ── Category Archive ──────────────────────────────────────────────────

    public function test_category_archive_returns_200_for_active_category(): void
    {
        $cat = PostCategory::factory()->create(['is_active' => true]);

        $this->get(route('blog.category', $cat->slug))->assertOk();
    }

    public function test_inactive_category_returns_404(): void
    {
        $cat = PostCategory::factory()->create(['is_active' => false]);

        $this->get(route('blog.category', $cat->slug))->assertNotFound();
    }

    public function test_category_archive_shows_published_posts(): void
    {
        $cat = PostCategory::factory()->create(['is_active' => true]);
        $post = Post::factory()->published()->create(['title' => 'Categorised Post']);
        $post->categories()->attach($cat);

        $this->get(route('blog.category', $cat->slug))
            ->assertOk()
            ->assertSee('Categorised Post');
    }

    public function test_category_archive_hides_draft_posts(): void
    {
        $cat = PostCategory::factory()->create(['is_active' => true]);
        $post = Post::factory()->draft()->create(['title' => 'Draft Categorised']);
        $post->categories()->attach($cat);

        $this->get(route('blog.category', $cat->slug))
            ->assertOk()
            ->assertDontSee('Draft Categorised');
    }

    // ── Tag Archive ───────────────────────────────────────────────────────

    public function test_tag_archive_returns_200_for_active_tag(): void
    {
        $tag = Tag::factory()->create(['is_active' => true]);

        $this->get(route('blog.tag', $tag->slug))->assertOk();
    }

    public function test_inactive_tag_returns_404(): void
    {
        $tag = Tag::factory()->create(['is_active' => false]);

        $this->get(route('blog.tag', $tag->slug))->assertNotFound();
    }

    public function test_tag_archive_shows_published_posts(): void
    {
        $tag = Tag::factory()->create(['is_active' => true]);
        $post = Post::factory()->published()->create(['title' => 'Tagged Post']);
        $post->tags()->attach($tag);

        $this->get(route('blog.tag', $tag->slug))
            ->assertOk()
            ->assertSee('Tagged Post');
    }

    // ── Search ────────────────────────────────────────────────────────────

    public function test_search_page_returns_200(): void
    {
        $this->get(route('search.index'))->assertOk();
    }

    public function test_search_returns_published_content(): void
    {
        $page = Page::factory()->create([
            'title' => 'Uniqueterm Page',
            'status' => PageStatus::Published,
            'visibility' => PageVisibility::Public,
        ]);

        $this->get(route('search.index', ['q' => 'Uniqueterm']))
            ->assertOk()
            ->assertSee('Uniqueterm Page');
    }

    // ── 404 Error Page ────────────────────────────────────────────────────

    public function test_missing_page_returns_404(): void
    {
        $this->get('/this-page-does-not-exist')->assertNotFound();
    }

    public function test_missing_blog_post_returns_404(): void
    {
        $this->get('/blog/no-such-article')->assertNotFound();
    }

    // ── Navigation ────────────────────────────────────────────────────────

    public function test_blog_index_uses_frontend_layout(): void
    {
        $this->get(route('blog.index'))
            ->assertOk()
            ->assertSee('<!DOCTYPE html>', false);
    }

    public function test_search_uses_frontend_layout(): void
    {
        $this->get(route('search.index'))
            ->assertOk()
            ->assertSee('<!DOCTYPE html>', false);
    }
}
