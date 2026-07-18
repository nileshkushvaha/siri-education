<?php

declare(strict_types=1);

namespace App\Services\Faq;

use App\Enums\FaqAudience;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class FaqService
{
    private const CACHE_TTL = 300;

    private const CATEGORIES_CACHE_KEY = 'faq.categories';

    public function categories(): Collection
    {
        $cached = Cache::get(self::CATEGORIES_CACHE_KEY);

        if ($cached instanceof Collection && $cached->every(
            static fn (mixed $category): bool => $category instanceof FaqCategory
        )) {
            return $cached;
        }

        // Cached Eloquent models may become unserialisable after a deployment,
        // class-map change, or interrupted cache write. Never let that stale
        // value turn the public help centre into a 500 response.
        Cache::forget(self::CATEGORIES_CACHE_KEY);

        $categories = FaqCategory::query()
            ->active()
            ->orderBy('display_order')
            ->orderBy('name')
            ->withCount(['publishedFaqs'])
            ->get();

        Cache::put(self::CATEGORIES_CACHE_KEY, $categories, self::CACHE_TTL);

        return $categories;
    }

    public function publicFaqs(?string $search = null, ?string $categoryId = null, ?int $limit = null): Collection
    {
        return $this->query([FaqAudience::Public->value], $search, $categoryId, $limit);
    }

    public function publicFaqsForCategory(string $categoryName, ?int $limit = null): Collection
    {
        $category = FaqCategory::query()->where('name', $categoryName)->first();

        if ($category === null) {
            return collect();
        }

        return $this->query([FaqAudience::Public->value], null, $category->id, $limit);
    }

    public function forUser(User $user, ?string $search = null, ?string $categoryId = null): Collection
    {
        return $this->query($this->audiencesForUser($user), $search, $categoryId);
    }

    public function featured(array $audiences, int $limit = 5): Collection
    {
        return $this->baseQuery($audiences)
            ->featured()
            ->limit($limit)
            ->with('category')
            ->get();
    }

    /** Featured public FAQs first, with the normal public ordering as a safe fallback. */
    public function homepage(int $limit = 6): Collection
    {
        $limit = max(1, min(12, $limit));
        $featured = $this->featured([FaqAudience::Public->value], $limit);

        return $featured->isNotEmpty() ? $featured : $this->publicFaqs(limit: $limit);
    }

    /** @return string[] */
    public function audiencesForUser(User $user): array
    {
        $audiences = [FaqAudience::Public->value];

        foreach (FaqAudience::cases() as $audience) {
            if ($audience === FaqAudience::Public) {
                continue;
            }

            if ($user->hasRole($audience->value)) {
                $audiences[] = $audience->value;
            }
        }

        return array_unique($audiences);
    }

    public function clearCache(): void
    {
        Cache::forget(self::CATEGORIES_CACHE_KEY);
    }

    // ── Private ───────────────────────────────────────────────────────────

    private function query(array $audiences, ?string $search, ?string $categoryId, ?int $limit = null): Collection
    {
        $query = $this->baseQuery($audiences);

        if ($search !== null && $search !== '') {
            $query->searchTerm($search);
        }

        if ($categoryId !== null && $categoryId !== '') {
            $query->where('faq_category_id', $categoryId);
        }

        return $query
            ->orderBy('display_order')
            ->orderBy('question')
            ->with('category')
            ->when($limit, fn (Builder $q, int $l) => $q->limit($l))
            ->get();
    }

    private function baseQuery(array $audiences): Builder
    {
        return Faq::query()
            ->published()
            ->forAudience($audiences);
    }
}
