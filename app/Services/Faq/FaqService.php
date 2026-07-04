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

    public function categories(): Collection
    {
        return Cache::remember('faq.categories', self::CACHE_TTL, fn () => FaqCategory::query()
            ->active()
            ->orderBy('display_order')
            ->orderBy('name')
            ->withCount(['publishedFaqs'])
            ->get()
        );
    }

    public function publicFaqs(?string $search = null, ?string $categoryId = null, ?int $limit = null): Collection
    {
        return $this->query([FaqAudience::Public->value], $search, $categoryId, $limit);
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
        Cache::forget('faq.categories');
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
