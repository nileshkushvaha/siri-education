<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Layout;

use App\Enums\Navigation\NavigationLocation;
use App\Navigation\DTOs\NavigationTree;
use App\Navigation\Services\NavigationManager;
use App\Services\PostService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteFooter extends Component
{
    public string $appName = '';

    public ?string $logo = null;

    public ?string $footerText = null;

    public ?string $footerCopyright = null;

    public ?string $supportEmail = null;

    public ?string $supportPhone = null;

    public ?string $address = null;

    private NavigationManager $navigation;

    private PostService $posts;

    public function boot(NavigationManager $navigation, PostService $posts): void
    {
        $this->navigation = $navigation;
        $this->posts = $posts;
    }

    public function mount(
        string $appName,
        ?string $logo = null,
        ?string $footerText = null,
        ?string $footerCopyright = null,
        ?string $supportEmail = null,
        ?string $supportPhone = null,
        ?string $address = null,
    ): void {
        $this->appName = $appName;
        $this->logo = $logo;
        $this->footerText = $footerText;
        $this->footerCopyright = $footerCopyright;
        $this->supportEmail = $supportEmail;
        $this->supportPhone = $supportPhone;
        $this->address = $address;
    }

    #[Computed]
    public function footerNavigation(): ?NavigationTree
    {
        return $this->navigation->forLocation(NavigationLocation::Footer, app()->getLocale());
    }

    /**
     * Drives the footer's "Learning" column. Optional: when no menu is
     * published at this location the column keeps its previous
     * behaviour, so adding the location never blanks the footer.
     */
    #[Computed]
    public function learningNavigation(): ?NavigationTree
    {
        return $this->navigation->forLocation(NavigationLocation::FooterLearning, app()->getLocale());
    }

    #[Computed]
    public function latestPosts(): Collection
    {
        return $this->posts->latestPublishedPosts();
    }

    public function render(): View
    {
        return view('livewire.frontend.layout.site-footer');
    }
}
