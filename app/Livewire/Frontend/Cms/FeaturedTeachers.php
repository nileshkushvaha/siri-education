<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Cms;

use App\Services\Instructor\RecommendationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Livewire\Component;

/**
 * The same CMS block drives every Version 1 recommendation
 * section via $section, reusing the existing audited page/post block
 * save flow as its "admin configuration" (requirement #9) rather than a
 * new settings page. $section defaults to 'featured' so every block
 * already placed on existing pages keeps its original behavior.
 */
final class FeaturedTeachers extends Component
{
    public string $eyebrow = '';

    public string $title = '';

    public string $description = '';

    public int $limit = 4;

    public int $columns = 4;

    public string $linkLabel = '';

    public string $linkUrl = '';

    public string $section = 'featured';

    public function mount(
        string $eyebrow = '',
        string $title = '',
        string $description = '',
        int $limit = 4,
        int $columns = 4,
        string $linkLabel = '',
        string $linkUrl = '',
        string $section = 'featured',
    ): void {
        $this->eyebrow = $eyebrow;
        $this->title = $title;
        $this->description = $description;
        $this->limit = max(1, min(12, $limit));
        $this->columns = $columns;
        $this->linkLabel = $linkLabel;
        $this->linkUrl = $linkUrl;
        $this->section = $section;
    }

    public function render(RecommendationService $recommendations, Request $request): View
    {
        $teachers = match ($this->section) {
            'popular' => $recommendations->popular($request, $this->limit),
            'new' => $recommendations->newInstructors($request, $this->limit),
            'recommended_for_you' => $recommendations->recommendedForYou($request->user(), $request, $this->limit),
            default => $recommendations->featured($request, $this->limit),
        };

        return view('livewire.frontend.cms.featured-teachers', [
            'teachers' => $teachers,
        ]);
    }
}
