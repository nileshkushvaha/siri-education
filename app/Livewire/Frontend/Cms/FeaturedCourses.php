<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Cms;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class FeaturedCourses extends Component
{
    public string $eyebrow = '';

    public string $title = '';

    public string $description = '';

    public array $courses = [];

    public int $columns = 3;

    public string $linkLabel = '';

    public string $linkUrl = '';

    public function mount(
        string $eyebrow = '',
        string $title = '',
        string $description = '',
        array $courses = [],
        int $columns = 3,
        string $linkLabel = '',
        string $linkUrl = '',
    ): void {
        $this->eyebrow = $eyebrow;
        $this->title = $title;
        $this->description = $description;
        $this->courses = $courses;
        $this->columns = $columns;
        $this->linkLabel = $linkLabel;
        $this->linkUrl = $linkUrl;
    }

    public function render(): View
    {
        return view('livewire.frontend.cms.featured-courses');
    }
}
