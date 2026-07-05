<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Layout;

use App\Services\SearchService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class SearchOverlay extends Component
{
    public bool $open = false;

    public string $query = '';

    private SearchService $search;

    public function boot(SearchService $search): void
    {
        $this->search = $search;
    }

    #[On('public-search-opened')]
    public function open(): void
    {
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->query = '';
    }

    /**
     * @return array{teachers: Collection, pages: Collection, posts: Collection, faqs: Collection}
     */
    #[Computed]
    public function results(): array
    {
        return $this->search->quickSearch($this->query, 5);
    }

    #[Computed]
    public function hasResults(): bool
    {
        return collect($this->results)->contains(fn ($category) => $category->isNotEmpty());
    }

    public function render(): View
    {
        return view('livewire.frontend.layout.search-overlay');
    }
}
