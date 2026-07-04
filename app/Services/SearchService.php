<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Faq\FaqService;
use App\Services\Instructor\InstructorService;
use Illuminate\Support\Collection;

/**
 * Single entry point for the site-wide quick-search widget
 * (SearchOverlay). Delegates to each domain's existing search method —
 * no query logic lives here, only aggregation.
 */
final class SearchService
{
    public function __construct(
        private readonly InstructorService $instructors,
        private readonly PageService $pages,
        private readonly PostService $posts,
        private readonly FaqService $faqs,
    ) {}

    /**
     * @return array{teachers: Collection, courses: Collection, pages: Collection, posts: Collection, faqs: Collection}
     */
    public function quickSearch(string $term, int $perCategory = 5): array
    {
        $term = trim($term);

        if ($term === '') {
            return [
                'teachers' => new Collection,
                'courses' => new Collection,
                'pages' => new Collection,
                'posts' => new Collection,
                'faqs' => new Collection,
            ];
        }

        return [
            'teachers' => $this->instructors->search($term, $perCategory),
            // No Course domain exists yet — kept as an explicit, always-empty
            // category rather than mapping onto an unrelated model.
            'courses' => new Collection,
            'pages' => $this->pages->searchPublishedPages($term, $perCategory),
            'posts' => $this->posts->searchPublishedPosts($term, $perCategory),
            'faqs' => $this->faqs->publicFaqs($term, limit: $perCategory),
        ];
    }
}
