<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Cms;

use App\Services\Instructor\InstructorService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

final class FeaturedTeachers extends Component
{
    public string $eyebrow = '';

    public string $title = '';

    public string $description = '';

    public int $limit = 4;

    public int $columns = 4;

    public string $linkLabel = '';

    public string $linkUrl = '';

    public function mount(
        string $eyebrow = '',
        string $title = '',
        string $description = '',
        int $limit = 4,
        int $columns = 4,
        string $linkLabel = '',
        string $linkUrl = '',
    ): void {
        $this->eyebrow = $eyebrow;
        $this->title = $title;
        $this->description = $description;
        $this->limit = max(1, min(12, $limit));
        $this->columns = $columns;
        $this->linkLabel = $linkLabel;
        $this->linkUrl = $linkUrl;
    }

    public function render(InstructorService $instructors): View
    {
        return view('livewire.frontend.cms.featured-teachers', [
            'teachers' => $this->featuredTeachers($instructors),
        ]);
    }

    private function featuredTeachers(InstructorService $instructors): Collection
    {
        return $instructors->featured($this->limit);
    }
}
