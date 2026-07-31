<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Layout;

use App\Enums\Navigation\NavigationLocation;
use App\Navigation\DTOs\NavigationTree;
use App\Navigation\Services\NavigationManager;
use App\Services\PortalResolver;
use App\Settings\GeneralSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteHeader extends Component
{
    public string $appName = '';

    public ?string $logo = null;

    public bool $mobileOpen = false;

    public bool $searchOpen = false;

    private NavigationManager $navigation;

    private PortalResolver $portalResolver;

    private GeneralSettings $settings;

    public function boot(NavigationManager $navigation, PortalResolver $portalResolver, GeneralSettings $settings): void
    {
        $this->navigation = $navigation;
        $this->portalResolver = $portalResolver;
        $this->settings = $settings;
    }

    public function mount(string $appName, ?string $logo = null): void
    {
        $this->appName = $appName;
        $this->logo = $logo;
    }

    public function toggleMobile(): void
    {
        $this->mobileOpen = ! $this->mobileOpen;
    }

    public function openSearch(): void
    {
        $this->searchOpen = true;
        $this->dispatch('public-search-opened');
    }

    public function closeSearch(): void
    {
        $this->searchOpen = false;
    }

    #[Computed]
    public function headerNavigation(): ?NavigationTree
    {
        return $this->navigation->forLocation(NavigationLocation::Header, app()->getLocale());
    }

    #[Computed]
    public function mobileNavigation(): ?NavigationTree
    {
        return $this->navigation->forLocation(NavigationLocation::Mobile, app()->getLocale())
            ?? $this->navigation->forLocation(NavigationLocation::Header, app()->getLocale());
    }

    public function dashboardUrl(): ?string
    {
        $user = auth()->user();

        return $user ? $this->portalResolver->dashboardRoute($user) : null;
    }

    /** @return array{enabled: bool, phone: ?string, email: ?string, socialLinks: array<string, string>} */
    public function topBar(): array
    {
        return [
            'enabled' => $this->settings->header_top_bar_enabled,
            'phone' => $this->settings->support_phone,
            'email' => $this->settings->support_email,
            'socialLinks' => array_filter([
                'facebook' => $this->settings->facebook_url,
                'instagram' => $this->settings->instagram_url,
                'x' => $this->settings->x_url,
                'youtube' => $this->settings->youtube_url,
            ]),
        ];
    }

    public function render(): View
    {
        return view('livewire.frontend.layout.site-header');
    }
}
