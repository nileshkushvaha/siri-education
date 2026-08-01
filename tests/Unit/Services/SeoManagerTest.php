<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Content\SEO\SeoManager;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoManagerTest extends TestCase
{
    use RefreshDatabase;

    private SeoManager $seo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seo = app(SeoManager::class);
    }

    // ── Page metadata ────────────────────────────────────────────────────

    public function test_page_metadata_uses_explicit_meta_fields(): void
    {
        $page = Page::factory()->create([
            'title' => 'About Us',
            'meta_title' => 'Custom Title',
            'meta_description' => 'Custom Desc',
            'meta_keywords' => 'foo, bar',
            // Pass without space — normaliseRobots adds ', ' after each ','
            'robots' => 'noindex,follow',
        ]);

        $meta = $this->seo->getPageMetadata($page);

        $this->assertSame('Custom Title', $meta['title']);
        $this->assertSame('Custom Desc', $meta['description']);
        $this->assertSame('foo, bar', $meta['keywords']);
        $this->assertSame('noindex, follow', $meta['robots']);
    }

    public function test_page_metadata_falls_back_to_title_when_meta_title_empty(): void
    {
        $page = Page::factory()->create([
            'title' => 'Page Title',
            'meta_title' => null,
        ]);

        $meta = $this->seo->getPageMetadata($page);

        $this->assertSame('Page Title', $meta['title']);
    }

    public function test_page_metadata_og_type_is_website(): void
    {
        $page = Page::factory()->create();

        $meta = $this->seo->getPageMetadata($page);

        $this->assertSame('website', $meta['og_type']);
    }

    public function test_page_metadata_canonical_uses_explicit_value(): void
    {
        $page = Page::factory()->create([
            'canonical_url' => 'https://example.com/canonical',
        ]);

        $meta = $this->seo->getPageMetadata($page);

        $this->assertSame('https://example.com/canonical', $meta['canonical']);
    }

    public function test_page_metadata_canonical_falls_back_to_route(): void
    {
        $page = Page::factory()->create([
            'slug' => 'about-us',
            'canonical_url' => null,
        ]);

        $meta = $this->seo->getPageMetadata($page);

        $this->assertStringContainsString('about-us', $meta['canonical']);
    }

    public function test_home_page_canonical_uses_home_route(): void
    {
        $page = Page::factory()->create(['slug' => 'home', 'canonical_url' => null]);

        $meta = $this->seo->getPageMetadata($page);

        $this->assertSame(route('home'), $meta['canonical']);
    }

    // ── Page structured data ─────────────────────────────────────────────

    public function test_page_structured_data_has_schema_org_context(): void
    {
        $page = Page::factory()->create();

        $data = $this->seo->getPageStructuredData($page);

        $this->assertSame('https://schema.org', $data['@context']);
        $this->assertSame('WebPage', $data['@type']);
        $this->assertSame($page->title, $data['name']);
    }

    public function test_page_structured_data_includes_modified_date(): void
    {
        $page = Page::factory()->create();

        $data = $this->seo->getPageStructuredData($page);

        $this->assertSame($page->updated_at->toIso8601String(), $data['dateModified']);
    }

    // ── Post metadata ────────────────────────────────────────────────────

    public function test_post_metadata_og_type_is_article(): void
    {
        $post = Post::factory()->published()->create();

        $meta = $this->seo->getPostMetadata($post);

        $this->assertSame('article', $meta['og_type']);
    }

    public function test_post_metadata_uses_explicit_meta_fields(): void
    {
        $post = Post::factory()->published()->create([
            'meta_title' => 'Post Meta Title',
            'meta_description' => 'Post Meta Desc',
            // normaliseRobots adds ', ' after ','; pass without space to get clean output
            'robots' => 'noindex,nofollow',
        ]);

        $meta = $this->seo->getPostMetadata($post);

        $this->assertSame('Post Meta Title', $meta['title']);
        $this->assertSame('Post Meta Desc', $meta['description']);
        $this->assertSame('noindex, nofollow', $meta['robots']);
    }

    public function test_post_metadata_canonical_falls_back_to_blog_route(): void
    {
        $post = Post::factory()->published()->create(['canonical_url' => null]);

        $meta = $this->seo->getPostMetadata($post);

        $this->assertStringContainsString($post->slug, $meta['canonical']);
    }

    // ── Post structured data ─────────────────────────────────────────────

    public function test_post_structured_data_type_is_article(): void
    {
        $post = Post::factory()->published()->create(['title' => 'Tech Post']);

        $data = $this->seo->getPostStructuredData($post);

        $this->assertSame('https://schema.org', $data['@context']);
        $this->assertSame('Article', $data['@type']);
        $this->assertSame('Tech Post', $data['headline']);
    }

    public function test_post_structured_data_includes_author_name(): void
    {
        $author = User::factory()->create(['name' => 'Jane Doe']);
        $post = Post::factory()->published()->create(['author_id' => $author->id]);
        $post->load('author');

        $data = $this->seo->getPostStructuredData($post);

        $this->assertSame('Person', $data['author']['@type']);
        $this->assertSame('Jane Doe', $data['author']['name']);
    }

    // ── URL helpers ──────────────────────────────────────────────────────

    public function test_page_url_returns_home_route_for_home_slug(): void
    {
        $page = Page::factory()->create(['slug' => 'home']);

        $this->assertSame(route('home'), $this->seo->pageUrl($page));
    }

    public function test_page_url_returns_page_show_route_for_other_slugs(): void
    {
        $page = Page::factory()->create(['slug' => 'contact']);

        $this->assertSame(route('page.show', 'contact'), $this->seo->pageUrl($page));
    }

    public function test_post_url_returns_blog_show_route(): void
    {
        $post = Post::factory()->published()->create(['slug' => 'my-article']);

        $this->assertSame(route('blog.show', 'my-article'), $this->seo->postUrl($post));
    }
}
