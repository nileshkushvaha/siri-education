<?php

declare(strict_types=1);

namespace App\Content\Rendering;

use App\Content\Contracts\HasContentBlocks;
use App\Content\SEO\SeoManager;
use App\Enums\PageContentWidth;
use App\Models\Page;
use App\Models\Post;
use App\Services\BlockRenderer;
use App\Settings\GeneralSettings;
use App\Settings\SeoSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Unified content renderer.
 *
 * Rendering order per owner:
 *   1. Content blocks where position = 'before_content'
 *   2. Primary rich content (<section class="cms-content">)
 *   3. Content blocks where position = 'after_content'
 *
 * Backward compatible: owners with no rich content and/or no before-blocks
 * continue rendering exactly as before.
 */
class ContentRenderer
{
    public function __construct(
        private readonly BlockRenderer $blockRenderer,
        private readonly SeoManager $seoManager,
        private readonly GeneralSettings $generalSettings,
        private readonly SeoSettings $seoSettings,
    ) {}

    // ── Generalised API ──────────────────────────────────────────────────

    public function renderContent(HasContentBlocks $owner): string
    {
        return $this->renderContentUncached($owner);
    }

    public function renderContentPreview(HasContentBlocks $owner): string
    {
        return $this->renderContentUncached($owner);
    }

    public function invalidateContentCache(HasContentBlocks $owner): void
    {
        cache()->forget($this->contentCacheKey($owner));
    }

    // ── Backward-compatible Page API ─────────────────────────────────────

    public function render(Page $page): string
    {
        return $this->renderContent($page);
    }

    public function renderPreview(Page $page): string
    {
        return $this->renderContentPreview($page);
    }

    public function invalidateCache(?Page $page): void
    {
        if ($page) {
            $this->invalidateContentCache($page);
        }
    }

    public function getSeoMetadata(Page $page): array
    {
        return $this->seoManager->getPageMetadata($page);
    }

    public function getStructuredData(Page $page): array
    {
        return $this->seoManager->getPageStructuredData($page);
    }

    // ── Backward-compatible Post API ─────────────────────────────────────

    public function renderPost(Post $post): string
    {
        return $this->renderContent($post);
    }

    public function renderPostPreview(Post $post): string
    {
        return $this->renderContentPreview($post);
    }

    public function invalidatePostCache(?Post $post): void
    {
        if ($post) {
            $this->invalidateContentCache($post);
        }
    }

    public function getPostSeoMetadata(Post $post): array
    {
        return $this->seoManager->getPostMetadata($post);
    }

    public function getPostStructuredData(Post $post): array
    {
        return $this->seoManager->getPostStructuredData($post);
    }

    // ── Core rendering pipeline ──────────────────────────────────────────

    private function renderContentUncached(HasContentBlocks $owner): string
    {
        $combined = cache()->remember(
            $this->contentCacheKey($owner),
            now()->addSeconds($this->renderTtl()),
            fn () => $this->assembleCombinedContent($owner)
        );

        $seo = $this->resolveSeo($owner);
        $structured = $this->resolveStructuredData($owner);

        return $this->applyLayout($combined, $owner, $seo, $structured);
    }

    private function assembleCombinedContent(HasContentBlocks $owner): string
    {
        $allBlocks = $owner->blocks;

        $beforeHtml = $this->renderBlocks(
            $allBlocks->where('is_active', true)->where('position', 'before_content')
        );

        $richHtml = $this->renderRichContent($owner);

        $afterHtml = $this->renderBlocks(
            $allBlocks->where('is_active', true)
                ->whereIn('position', ['after_content', null, ''])
        );

        return $beforeHtml.$richHtml.$afterHtml;
    }

    private function renderRichContent(HasContentBlocks $owner): string
    {
        $content = $owner->content ?? null;

        if (blank($content) || blank(strip_tags((string) $content))) {
            return '';
        }

        $contentWidth = $this->resolveContentWidth($owner);

        return '<section class="py-12" data-cms-rich-content><div class="'.$contentWidth->contentContainerClasses().'" data-cms-content-container="'.$contentWidth->value.'"><div class="cms-content">'.$content.'</div></div></section>';
    }

    private function renderBlocks(Collection $blocks): string
    {
        $sorted = $blocks->sortBy('sort_order');

        if ($sorted->isEmpty()) {
            return '';
        }

        return $sorted
            ->map(fn ($block) => $this->renderSingleBlock($block))
            ->join("\n");
    }

    private function renderSingleBlock(object $block): string
    {
        try {
            return $this->blockRenderer->render($block);
        } catch (\Exception $e) {
            $blockType = $block->block_type->value ?? (string) ($block->block_type ?? 'unknown');

            return $this->renderBlockError($blockType, $e->getMessage());
        }
    }

    private function applyLayout(string $content, object $contentModel, array $seo, array $structuredData): string
    {
        $layoutName = $contentModel->template ?? 'default';
        $layout = match ($layoutName) {
            'landing' => 'layouts.landing',
            'blank' => 'layouts.blank',
            default => 'layouts.page',
        };

        return view($layout, [
            'content' => $content,
            'page' => $contentModel,
            'post' => $contentModel instanceof Post ? $contentModel : null,
            'seo' => $seo,
            'structured_data' => $structuredData,
            'site' => $this->getSiteMetadata(),
            'content_width' => $this->resolveContentWidth($contentModel)->value,
            'content_width_classes' => $this->resolveContentWidth($contentModel)->pageShellClasses(),
        ])->render();
    }

    private function resolveContentWidth(object $owner): PageContentWidth
    {
        return PageContentWidth::resolve($owner instanceof Page ? $owner->layout : null);
    }

    private function resolveSeo(HasContentBlocks $owner): array
    {
        return match (true) {
            $owner instanceof Post => $this->seoManager->getPostMetadata($owner),
            $owner instanceof Page => $this->seoManager->getPageMetadata($owner),
            default => [],
        };
    }

    private function resolveStructuredData(HasContentBlocks $owner): array
    {
        return match (true) {
            $owner instanceof Post => $this->seoManager->getPostStructuredData($owner),
            $owner instanceof Page => $this->seoManager->getPageStructuredData($owner),
            default => [],
        };
    }

    private function contentCacheKey(HasContentBlocks $owner): string
    {
        $type = class_basename($owner);
        $id = method_exists($owner, 'getKey') ? $owner->getKey() : spl_object_id($owner);

        return strtolower("{$type}-render:{$id}");
    }

    private function toStorageUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }
        if (str_starts_with($path, 'http') || str_starts_with($path, '//')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    private function renderTtl(): int
    {
        return max(1, (int) config('cms.cache.page_render_ttl', 3600));
    }

    private function renderBlockError(string $blockType, string $error): string
    {
        if (app()->isProduction()) {
            return '';
        }

        return sprintf(
            '<div class="alert alert-danger p-4 m-4"><strong>Block Error (%s):</strong> %s</div>',
            str($blockType)->kebab(),
            htmlspecialchars($error)
        );
    }

    private function getSiteMetadata(): array
    {
        $general = $this->generalSettings;

        if (! $general) {
            return [
                'app_name' => config('app.name'),
                'logo' => null,
                'favicon' => null,
                'footer_text' => null,
                'footer_copyright' => null,
                'google_analytics_id' => null,
                'google_tag_manager_id' => null,
                'facebook_pixel_id' => null,
                'google_search_console_verification' => null,
            ];
        }

        return [
            'app_name' => $general->app_name,
            'logo' => $this->toStorageUrl($general->logo),
            'favicon' => $this->toStorageUrl($general->favicon),
            'footer_text' => $general->footer_text,
            'footer_copyright' => $general->footer_copyright,
            'google_analytics_id' => $this->seoSettings->google_analytics_id,
            'google_tag_manager_id' => $this->seoSettings->google_tag_manager_id,
            'facebook_pixel_id' => $this->seoSettings->facebook_pixel_id,
            'google_search_console_verification' => $this->seoSettings->google_search_console_verification,
        ];
    }
}
