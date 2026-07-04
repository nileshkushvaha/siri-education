<?php

namespace App\Services;

use App\Models\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

class PageService
{
    /**
     * Get all pages with optional filters
     */
    public function getPages(
        ?string $status = null,
        ?string $visibility = null,
        ?string $template = null,
        ?string $search = null,
        int $perPage = 15
    ): Paginator {
        $query = Page::query();

        if ($status) {
            $query->where('status', $status);
        }

        if ($visibility) {
            $query->where('visibility', $visibility);
        }

        if ($template) {
            $query->where('template', $template);
        }

        if ($search) {
            $query->search($search);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * Get published pages
     */
    public function getPublishedPages(int $perPage = 15): Paginator
    {
        return Page::published()
            ->orderByDesc('published_at')
            ->paginate($perPage);
    }

    public function getPublishedPage(string $slug): ?Page
    {
        return Page::query()
            ->published()
            ->where('slug', $slug)
            ->with(['blocks' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')])
            ->first();
    }

    public function getPublishedPageById(int|string $id): ?Page
    {
        return Page::query()
            ->published()
            ->where('id', $id)
            ->with(['blocks' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')])
            ->first();
    }

    public function getHomepage(): ?Page
    {
        return $this->getPublishedPage('home');
    }

    /**
     * Get featured pages (for dashboard, etc.)
     */
    public function getFeaturedPages(int $limit = 5): Collection
    {
        return Page::published()
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }

    public function searchPublishedPages(string $term, int $limit = 10): Collection
    {
        return Page::query()
            ->published()
            ->when($term !== '', fn ($query) => $query->search($term))
            ->latest('updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Duplicate a page inside a transaction so a media or block failure rolls back everything.
     */
    public function duplicatePage(Page $page): Page
    {
        return DB::transaction(function () use ($page): Page {
            $newPage = $page->replicate();
            $newPage->slug = $this->generateUniqueSlug($page->slug.'-copy');
            $newPage->title = $page->title.' (Copy)';
            $newPage->status = 'draft';
            $newPage->visibility = 'private';
            $newPage->published_at = null;
            $newPage->save();

            foreach ($page->blocks as $block) {
                $newBlock = $block->replicate(['id']);
                $newBlock->blockable_id = $newPage->id;
                $newBlock->save();
            }

            $page->getMedia('featured-image')->each(function ($media) use ($newPage): void {
                $media->copy($newPage, 'featured-image');
            });

            return $newPage;
        });
    }

    /**
     * Publish a page
     */
    public function publishPage(Page $page): bool
    {
        return $page->publish();
    }

    /**
     * Unpublish a page
     */
    public function unpublishPage(Page $page): bool
    {
        return $page->unpublish();
    }

    /**
     * Archive a page
     */
    public function archivePage(Page $page): bool
    {
        return $page->archive();
    }

    /**
     * Generate unique slug
     */
    private function generateUniqueSlug(string $slug): string
    {
        $baseSlug = $slug;
        $counter = 1;

        while (Page::where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
