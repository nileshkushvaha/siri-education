<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Content\Models\ContentBlock;
use App\Content\Rendering\ContentRenderer;
use App\Content\SEO\SeoManager;
use App\Enums\BlockType;
use App\Enums\PageStatus;
use App\Enums\PageVisibility;
use App\Models\Page;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the layered content rendering pipeline:
 *   before-blocks → rich content → after-blocks
 */
class RichContentRenderingTest extends TestCase
{
    use RefreshDatabase;

    // ── Helper factories ─────────────────────────────────────────────────

    private function publishedPage(array $attrs = []): Page
    {
        return Page::factory()->create(array_merge([
            'status' => PageStatus::Published,
            'visibility' => PageVisibility::Public,
            'template' => 'default',
        ], $attrs));
    }

    private function publishedPost(array $attrs = []): Post
    {
        return Post::factory()->published()->create($attrs);
    }

    private function attachBlock(Page|Post $owner, string $position = 'after_content'): ContentBlock
    {
        return ContentBlock::factory()->create([
            'blockable_type' => $owner->getMorphClass(),
            'blockable_id' => $owner->getKey(),
            'block_type' => BlockType::RichText,
            'content' => ['text' => '<p>Block paragraph.</p>'],
            'is_active' => true,
            'sort_order' => 0,
            'position' => $position,
        ]);
    }

    // ── Page: rich content only ───────────────────────────────────────────

    public function test_page_with_rich_content_only_renders_cms_content_section(): void
    {
        $page = $this->publishedPage(['content' => '<p>Hello world</p>']);

        $html = app(ContentRenderer::class)->render($page);

        $this->assertStringContainsString('class="cms-content"', $html);
        $this->assertStringContainsString('Hello world', $html);
    }

    public function test_default_page_width_uses_centered_content_container(): void
    {
        $page = $this->publishedPage([
            'layout' => 'default',
            'content' => '<p>Centered content</p>',
        ]);

        $html = app(ContentRenderer::class)->render($page);

        $this->assertStringContainsString('data-content-width="default"', $html);
        $this->assertStringContainsString('data-content-width="default"', $html);
        $this->assertStringContainsString('data-cms-content-container="default"', $html);
        $this->assertStringContainsString('max-w-7xl mx-auto px-4 sm:px-6 lg:px-8', $html);
    }

    public function test_full_width_page_uses_edge_to_edge_content_container(): void
    {
        $page = $this->publishedPage([
            'layout' => 'full-width',
            'content' => '<p>Full width content</p>',
        ]);

        $html = app(ContentRenderer::class)->render($page);

        $this->assertStringContainsString('data-content-width="full-width"', $html);
        $this->assertStringContainsString('[&amp;_.cms-section]:px-4', $html);
        $this->assertStringContainsString('data-cms-content-container="full-width"', $html);
        $this->assertStringContainsString('<div class="w-full" data-cms-content-container="full-width">', $html);
        $this->assertStringNotContainsString('<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8" data-cms-content-container="full-width">', $html);
    }

    public function test_unavailable_sidebar_width_falls_back_to_default_container(): void
    {
        $page = $this->publishedPage([
            'layout' => 'sidebar-left',
            'content' => '<p>Fallback content</p>',
        ]);

        $html = app(ContentRenderer::class)->render($page);

        $this->assertStringContainsString('data-content-width="default"', $html);
        $this->assertStringContainsString('data-cms-content-container="default"', $html);
    }

    public function test_page_with_no_content_renders_no_cms_section(): void
    {
        $page = $this->publishedPage(['content' => null]);

        $html = app(ContentRenderer::class)->render($page);

        $this->assertStringNotContainsString('cms-content', $html);
    }

    // ── Page: blocks only ────────────────────────────────────────────────

    public function test_page_with_blocks_only_renders_blocks(): void
    {
        $page = $this->publishedPage(['content' => null]);
        $this->attachBlock($page, 'after_content');

        $html = app(ContentRenderer::class)->render($page);

        $this->assertStringContainsString('Block paragraph.', $html);
        $this->assertStringNotContainsString('cms-content', $html);
    }

    // ── Page: rich content + blocks ───────────────────────────────────────

    public function test_page_rich_content_and_blocks_both_render(): void
    {
        $page = $this->publishedPage(['content' => '<p>Rich text here</p>']);
        $this->attachBlock($page, 'after_content');

        $html = app(ContentRenderer::class)->render($page);

        $this->assertStringContainsString('Rich text here', $html);
        $this->assertStringContainsString('Block paragraph.', $html);
    }

    // ── Page: rendering order (before/after) ─────────────────────────────

    public function test_before_block_appears_before_rich_content(): void
    {
        $page = $this->publishedPage(['content' => '<p>Rich</p>']);
        $this->attachBlock($page, 'before_content');

        $html = app(ContentRenderer::class)->render($page);

        $beforePos = strpos($html, 'Block paragraph.');
        $richPos = strpos($html, 'Rich');

        $this->assertNotFalse($beforePos);
        $this->assertNotFalse($richPos);
        $this->assertLessThan($richPos, $beforePos, 'Before block must appear before rich content');
    }

    public function test_after_block_appears_after_rich_content(): void
    {
        $page = $this->publishedPage(['content' => '<p>Rich</p>']);
        $this->attachBlock($page, 'after_content');

        $html = app(ContentRenderer::class)->render($page);

        $afterPos = strpos($html, 'Block paragraph.');
        $richPos = strpos($html, 'Rich');

        $this->assertNotFalse($afterPos);
        $this->assertNotFalse($richPos);
        $this->assertGreaterThan($richPos, $afterPos, 'After block must appear after rich content');
    }

    // ── Page: empty ───────────────────────────────────────────────────────

    public function test_empty_page_renders_full_html_document(): void
    {
        $page = $this->publishedPage(['content' => null]);

        $html = app(ContentRenderer::class)->render($page);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<html', $html);
        $this->assertStringNotContainsString('cms-content', $html);
    }

    // ── Post: rich content only ───────────────────────────────────────────

    public function test_post_with_rich_content_renders_cms_content_section(): void
    {
        $post = $this->publishedPost(['content' => '<p>Post body</p>']);

        $html = app(ContentRenderer::class)->renderPost($post);

        $this->assertStringContainsString('class="cms-content"', $html);
        $this->assertStringContainsString('Post body', $html);
    }

    public function test_post_with_no_content_renders_no_cms_section(): void
    {
        $post = $this->publishedPost(['content' => null]);

        $html = app(ContentRenderer::class)->renderPost($post);

        $this->assertStringNotContainsString('cms-content', $html);
    }

    // ── Post: blocks only ────────────────────────────────────────────────

    public function test_post_blocks_only_renders_blocks(): void
    {
        $post = $this->publishedPost(['content' => null]);
        $this->attachBlock($post, 'after_content');

        $html = app(ContentRenderer::class)->renderPost($post);

        $this->assertStringContainsString('Block paragraph.', $html);
        $this->assertStringNotContainsString('cms-content', $html);
    }

    // ── Post: rich + blocks ───────────────────────────────────────────────

    public function test_post_rich_content_and_blocks_both_render(): void
    {
        $post = $this->publishedPost(['content' => '<p>Post rich</p>']);
        $this->attachBlock($post, 'after_content');

        $html = app(ContentRenderer::class)->renderPost($post);

        $this->assertStringContainsString('Post rich', $html);
        $this->assertStringContainsString('Block paragraph.', $html);
    }

    // ── Empty post ────────────────────────────────────────────────────────

    public function test_empty_post_renders_full_html_document(): void
    {
        $post = $this->publishedPost(['content' => null]);

        $html = app(ContentRenderer::class)->renderPost($post);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringNotContainsString('cms-content', $html);
    }

    // ── SEO fallback ─────────────────────────────────────────────────────

    public function test_page_seo_description_falls_back_to_excerpt(): void
    {
        $page = $this->publishedPage([
            'excerpt' => 'Excerpt text',
            'content' => '<p>Full content</p>',
            'meta_description' => null,
        ]);

        $html = app(ContentRenderer::class)->render($page);

        $this->assertStringContainsString('Excerpt text', $html);
    }

    public function test_page_seo_description_falls_back_to_content_when_no_excerpt(): void
    {
        $page = $this->publishedPage([
            'excerpt' => null,
            'content' => '<p>Content used as SEO description.</p>',
            'meta_description' => null,
        ]);

        $seo = app(SeoManager::class)->getPageMetadata($page);

        $this->assertStringContainsString('Content used as SEO description', $seo['description']);
    }

    public function test_post_seo_description_falls_back_to_content_when_no_excerpt(): void
    {
        $post = $this->publishedPost([
            'excerpt' => null,
            'content' => '<p>Post content for SEO.</p>',
            'meta_description' => null,
        ]);

        $seo = app(SeoManager::class)->getPostMetadata($post);

        $this->assertStringContainsString('Post content for SEO', $seo['description']);
    }

    // ── Search indexing ───────────────────────────────────────────────────

    public function test_page_search_scope_matches_content(): void
    {
        $page = $this->publishedPage(['content' => '<p>Unique phrase: xyzzy123</p>']);

        $results = Page::search('xyzzy123')->get();

        $this->assertTrue($results->contains($page));
    }

    public function test_post_search_scope_matches_content(): void
    {
        $post = $this->publishedPost(['content' => '<p>Unique phrase: abcde999</p>']);

        $results = Post::search('abcde999')->get();

        $this->assertTrue($results->contains($post));
    }

    // ── Excerpt fallback ─────────────────────────────────────────────────

    public function test_page_search_scope_matches_excerpt(): void
    {
        $page = $this->publishedPage(['excerpt' => 'Excerpt only content here']);

        $results = Page::search('Excerpt only content here')->get();

        $this->assertTrue($results->contains($page));
    }

    public function test_post_search_scope_matches_excerpt(): void
    {
        $post = $this->publishedPost(['excerpt' => 'Post excerpt search phrase']);

        $results = Post::search('Post excerpt search phrase')->get();

        $this->assertTrue($results->contains($post));
    }

    // ── Block position defaults ───────────────────────────────────────────

    public function test_block_without_position_treated_as_after_content(): void
    {
        $page = $this->publishedPage(['content' => '<p>Rich</p>']);

        ContentBlock::factory()->create([
            'blockable_type' => $page->getMorphClass(),
            'blockable_id' => $page->getKey(),
            'block_type' => BlockType::RichText,
            'content' => ['text' => '<p>Default positioned block.</p>'],
            'is_active' => true,
            'sort_order' => 0,
            'position' => 'after_content',
        ]);

        $html = app(ContentRenderer::class)->render($page);

        $richPos = strpos($html, 'Rich');
        $blockPos = strpos($html, 'Default positioned block.');

        $this->assertGreaterThan($richPos, $blockPos, 'Default block must appear after rich content');
    }

    // ── Backward compatibility ────────────────────────────────────────────

    public function test_existing_page_with_only_blocks_and_no_content_still_renders(): void
    {
        $page = $this->publishedPage(['content' => null]);
        $this->attachBlock($page, 'after_content');

        $response = $this->get(route('page.show', $page->slug));

        $response->assertStatus(200);
        $this->assertStringContainsString('Block paragraph.', $response->getContent());
    }
}
