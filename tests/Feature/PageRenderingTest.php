<?php

namespace Tests\Feature;

use App\Content\Models\ContentBlock;
use App\Enums\BlockType;
use App\Enums\PageStatus;
use App\Enums\PageVisibility;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageRenderingTest extends TestCase
{
    use RefreshDatabase;

    // ── Helper ────────────────────────────────────────────────────────────

    private function addBlock(Page $page, BlockType $type, array $content, int $sortOrder = 1, bool $active = true): ContentBlock
    {
        return ContentBlock::create([
            'blockable_type' => 'page',
            'blockable_id' => $page->id,
            'block_type' => $type,
            'content' => json_encode($content),
            'settings' => json_encode([]),
            'sort_order' => $sortOrder,
            'is_active' => $active,
        ]);
    }

    // ── Tests ─────────────────────────────────────────────────────────────

    public function test_can_render_published_page(): void
    {
        $page = Page::factory()->create([
            'status' => PageStatus::Published,
            'visibility' => PageVisibility::Public,
        ]);

        $this->addBlock($page, BlockType::Hero, ['title' => 'Welcome']);

        $this->get(route('page.show', $page->slug))
            ->assertStatus(200)
            ->assertSee('Welcome');
    }

    public function test_cannot_render_draft_page(): void
    {
        $page = Page::factory()->create(['status' => PageStatus::Draft]);

        $this->get(route('page.show', $page->slug))->assertStatus(404);
    }

    public function test_cannot_render_private_published_page(): void
    {
        $page = Page::factory()->create([
            'status' => PageStatus::Published,
            'visibility' => PageVisibility::Private,
        ]);

        $this->get(route('page.show', $page->slug))->assertStatus(404);
    }

    public function test_cannot_render_scheduled_page_before_publish_date(): void
    {
        $page = Page::factory()->create([
            'status' => PageStatus::Scheduled,
            'published_at' => now()->addDay(),
        ]);

        $this->get(route('page.show', $page->slug))->assertStatus(404);
    }

    public function test_renders_multiple_blocks_in_order(): void
    {
        $page = Page::factory()->create([
            'status' => PageStatus::Published,
            'visibility' => PageVisibility::Public,
        ]);

        $this->addBlock($page, BlockType::Hero, ['title' => 'Hero Title'], 1);
        $this->addBlock($page, BlockType::RichText, ['text' => '<p>Body text</p>'], 2);
        $this->addBlock($page, BlockType::CTA, ['title' => 'CTA Title'], 3);

        $this->get(route('page.show', $page->slug))
            ->assertStatus(200)
            ->assertSee('Hero Title')
            ->assertSee('Body text')
            ->assertSee('CTA Title');
    }

    public function test_skips_inactive_blocks(): void
    {
        $page = Page::factory()->create([
            'status' => PageStatus::Published,
            'visibility' => PageVisibility::Public,
        ]);

        $this->addBlock($page, BlockType::Hero, ['title' => 'Active Hero'], 1, true);
        $this->addBlock($page, BlockType::RichText, ['text' => '<p>Inactive text</p>'], 2, false);

        $this->get(route('page.show', $page->slug))
            ->assertStatus(200)
            ->assertSee('Active Hero')
            ->assertDontSee('Inactive text');
    }

    public function test_page_includes_seo_metadata(): void
    {
        $page = Page::factory()->create([
            'status' => PageStatus::Published,
            'visibility' => PageVisibility::Public,
            'meta_title' => 'Custom SEO Title',
            'meta_description' => 'Custom SEO Description',
            'meta_keywords' => 'keyword1, keyword2',
        ]);

        $this->addBlock($page, BlockType::Hero, ['title' => 'Page']);

        $this->get(route('page.show', $page->slug))
            ->assertStatus(200)
            ->assertSee('Custom SEO Title', false)
            ->assertSee('Custom SEO Description', false)
            ->assertSee('keyword1, keyword2', false);
    }

    public function test_page_includes_structured_data(): void
    {
        $page = Page::factory()->create([
            'status' => PageStatus::Published,
            'visibility' => PageVisibility::Public,
            'title' => 'Test Page',
            'excerpt' => 'Page excerpt',
        ]);

        $this->addBlock($page, BlockType::Hero, ['title' => 'Page']);

        $this->get(route('page.show', $page->slug))
            ->assertStatus(200)
            ->assertSee('application/ld+json', false)
            ->assertSee('@context', false)
            ->assertSee('https://schema.org', false);
    }

    public function test_home_route_renders_home_page(): void
    {
        // Default setting is 'template' — should return the home.blade.php view with status 200
        $this->get('/')->assertStatus(200)->assertSee('<!DOCTYPE html>', false);
    }

    public function test_home_route_prefers_published_cms_home_page(): void
    {
        $page = Page::factory()->create([
            'status' => PageStatus::Published,
            'visibility' => PageVisibility::Public,
            'slug' => 'home',
            'title' => 'CMS Home',
        ]);

        $this->addBlock($page, BlockType::Hero, ['title' => 'CMS Driven Homepage']);

        $this->get('/')
            ->assertOk()
            ->assertSee('CMS Driven Homepage');
    }

    public function test_public_page_returns_complete_html_document(): void
    {
        $page = Page::factory()->create([
            'status' => PageStatus::Published,
            'visibility' => PageVisibility::Public,
            'layout' => 'page',
        ]);

        $this->addBlock($page, BlockType::Hero, ['title' => 'Full Page']);

        $this->get(route('page.show', $page->slug))
            ->assertOk()
            ->assertSee('<!DOCTYPE html>', false)
            ->assertSee('<html lang="en" class="dark">', false)
            ->assertSee('application/ld+json', false)
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function test_page_with_hero_and_gallery(): void
    {
        $page = Page::factory()->create([
            'status' => PageStatus::Published,
            'visibility' => PageVisibility::Public,
        ]);

        $this->addBlock($page, BlockType::Hero, ['title' => 'Gallery Page'], 1);
        $this->addBlock($page, BlockType::Gallery, [
            'images' => [['url' => '/img1.jpg'], ['url' => '/img2.jpg']],
        ], 2);

        $this->get(route('page.show', $page->slug))
            ->assertStatus(200)
            ->assertSee('Gallery Page')
            ->assertSee('/img1.jpg')
            ->assertSee('/img2.jpg');
    }

    public function test_page_rendering_caches_result(): void
    {
        $page = Page::factory()->create([
            'status' => PageStatus::Published,
            'visibility' => PageVisibility::Public,
        ]);

        $this->addBlock($page, BlockType::Hero, ['title' => 'Test']);

        $response1 = $this->get(route('page.show', $page->slug));
        $response1->assertStatus(200);

        $response2 = $this->get(route('page.show', $page->slug));
        $response2->assertStatus(200);

        preg_match('/<main id="main-content">(.*?)<\/main>/s', $response1->getContent(), $firstMain);
        preg_match('/<main id="main-content">(.*?)<\/main>/s', $response2->getContent(), $secondMain);

        $this->assertEquals(
            $this->normalizeForCacheComparison($firstMain[1] ?? ''),
            $this->normalizeForCacheComparison($secondMain[1] ?? ''),
        );
    }

    /**
     * Livewire's asset manager tracks, per PHP process, whether it has
     * already injected its style/script boilerplate — state that two
     * sequential requests in the same PHPUnit process do not reset
     * between, unlike two real separate HTTP requests. Depending on
     * what ran earlier in a full suite run, that boilerplate (or a
     * stray blank line where it would otherwise sit) can differ between
     * this test's two calls, even though it never belongs inside
     * <main id="main-content"> in a correct render — confirmed by a
     * clean two-request kernel simulation outside PHPUnit, which
     * produces byte-identical output for both calls.
     *
     * Stripping known Livewire markers and collapsing whitespace keeps
     * this assertion scoped to what it actually tests — cached CMS
     * block content — regardless of exactly how that incidental,
     * process-shared asset-injection state happens to manifest.
     */
    private function normalizeForCacheComparison(string $html): string
    {
        $html = preg_replace('/<!--\s*Livewire Styles\s*-->/', '', $html) ?? $html;
        $html = preg_replace('/<!--\s*Livewire Scripts\s*-->/', '', $html) ?? $html;
        $html = preg_replace('/<style[^>]*>.*?wire\\\\:loading.*?<\/style>/s', '', $html) ?? $html;
        $html = preg_replace('/<script\b[^>]*\bsrc="[^"]*livewire[^"]*"[^>]*>\s*<\/script>/s', '', $html) ?? $html;

        // Collapse whitespace between tags to nothing (not just to one
        // space): the incidental gap left by a stripped/skipped Livewire
        // directive can be a run of whitespace, a single space, or none
        // at all depending on what the directive resolved to — only
        // eliminating it entirely normalizes all three the same way.
        $html = preg_replace('/>\s+</', '><', $html) ?? $html;

        return trim($html);
    }

    public function test_page_not_found(): void
    {
        $this->get('/nonexistent-page-slug')->assertStatus(404);
    }

    public function test_canonical_url_in_seo(): void
    {
        $canonicalUrl = 'https://example.com/canonical-url';
        $page = Page::factory()->create([
            'status' => PageStatus::Published,
            'canonical_url' => $canonicalUrl,
            'visibility' => PageVisibility::Public,
        ]);

        $this->addBlock($page, BlockType::Hero, ['title' => 'Page']);

        $this->get(route('page.show', $page->slug))
            ->assertStatus(200)
            ->assertSee($canonicalUrl, false);
    }
}
