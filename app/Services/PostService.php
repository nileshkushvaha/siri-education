<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PostService
{
    public function __construct(
        private readonly BlockRenderer $blockRenderer,
    ) {}

    public function getPosts(
        ?string $status = null,
        ?string $visibility = null,
        ?int $authorId = null,
        ?string $search = null,
        int $perPage = 15
    ): LengthAwarePaginator {
        $query = Post::query()->with(['author', 'media', 'categories', 'tags']);

        if ($status) {
            $query->where('status', $status);
        }

        if ($visibility) {
            $query->where('visibility', $visibility);
        }

        if ($authorId) {
            $query->where('author_id', $authorId);
        }

        if ($search) {
            $query->search($search);
        }

        return $query->latest('updated_at')->paginate($perPage);
    }

    public function getPublishedPosts(int $perPage = 12): LengthAwarePaginator
    {
        return Post::query()
            ->published()
            ->with(['author', 'media', 'categories', 'tags'])
            ->latest('published_at')
            ->paginate($perPage);
    }

    public function getFeaturedPosts(int $limit = 5): Collection
    {
        return Post::query()
            ->published()
            ->featured()
            ->with(['author', 'media'])
            ->latest('published_at')
            ->limit($limit)
            ->get();
    }

    public function latestPublishedPosts(int $limit = 3): Collection
    {
        return Post::query()
            ->published()
            ->with(['author', 'media'])
            ->latest('published_at')
            ->limit($limit)
            ->get();
    }

    public function searchPublishedPosts(string $term, int $limit = 10): Collection
    {
        return Post::query()
            ->published()
            ->with(['author', 'media'])
            ->when($term !== '', fn ($query) => $query->search($term))
            ->latest('updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Duplicate a post inside a transaction so any block, taxonomy, or media failure
     * rolls back the entire operation and leaves no orphaned records.
     */
    public function duplicatePost(Post $post): Post
    {
        return DB::transaction(function () use ($post): Post {
            $newPost = $post->replicate();
            $newPost->slug = $this->generateUniqueSlug($post->slug.'-copy');
            $newPost->title = $post->title.' (Copy)';
            $newPost->status = 'draft';
            $newPost->visibility = 'private';
            $newPost->published_at = null;
            $newPost->featured = false;
            $newPost->save();

            foreach ($post->blocks as $block) {
                $newBlock = $block->replicate(['id']);
                $newBlock->blockable_id = $newPost->id;
                $newBlock->save();
            }

            $post->loadMissing('categories');
            $newPost->categories()->sync($post->categories->modelKeys());

            $post->loadMissing('tags');
            $newPost->tags()->sync($post->tags->modelKeys());

            $post->loadMissing('relatedPosts');
            $newPost->relatedPosts()->sync($post->relatedPosts->modelKeys());

            $post->getMedia('featured-image')->each(fn ($media) => $media->copy($newPost, 'featured-image'));
            $post->getMedia('gallery')->each(fn ($media) => $media->copy($newPost, 'gallery'));

            $this->refreshReadingTime($newPost);

            return $newPost;
        });
    }

    public function publishPost(Post $post): bool
    {
        return $post->publish();
    }

    public function unpublishPost(Post $post): bool
    {
        return $post->unpublish();
    }

    public function archivePost(Post $post): bool
    {
        return $post->archive();
    }

    public function getRelatedPosts(Post $post, int $limit = 3): Collection
    {
        $post->loadMissing(['relatedPosts.author', 'categories', 'tags']);

        if ($post->relatedPosts->isNotEmpty()) {
            return $post->relatedPosts->take($limit);
        }

        return Post::query()
            ->published()
            ->whereKeyNot($post->getKey())
            ->where(function ($query) use ($post): void {
                $query->whereHas('categories', fn ($categoryQuery) => $categoryQuery->whereKey($post->categories->modelKeys()))
                    ->orWhereHas('tags', fn ($tagQuery) => $tagQuery->whereKey($post->tags->modelKeys()));
            })
            ->with('author')
            ->latest('published_at')
            ->limit($limit)
            ->get();
    }

    public function refreshReadingTime(Post $post): int
    {
        $post->loadMissing(['blocks' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')]);

        $words = 0;
        foreach ($post->blocks as $block) {
            if (! $block->is_active) {
                continue;
            }

            $html = $this->blockRenderer->render($block);
            $text = trim(strip_tags($html));
            $words += str_word_count($text);
        }

        $readingTime = max(1, (int) ceil($words / 200));
        $post->updateQuietly(['reading_time' => $readingTime]);

        return $readingTime;
    }

    private function generateUniqueSlug(string $slug): string
    {
        $baseSlug = $slug;
        $counter = 1;

        while (Post::query()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
