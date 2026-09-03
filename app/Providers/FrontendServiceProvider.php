<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\ThemeResolver;
use App\View\Composers\AccountPortalComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Bounded-module provider for the Public Frontend (Blade + Livewire +
 * Alpine), mirroring the one-provider-per-module convention already
 * used by BookingServiceProvider / NavigationServiceProvider /
 * CmsServiceProvider — kept separate from AppServiceProvider so
 * frontend-only concerns don't bloat that provider further.
 *
 * config/frontend.php is loaded automatically by Laravel (it lives in
 * config/, not a package path), and Blade/Livewire components under
 * resources/views/components/ and app/Livewire/ are auto-discovered
 * by convention — none of that needs registration here. This
 * provider's job is the frontend-specific wiring that isn't
 * auto-discovered: view composers.
 */
class FrontendServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerViewComposers();
    }

    /**
     * Account Portal pages share their layout/menu/profile-summary data via
     * this composer instead of each controller repeating the same queries.
     *
     * Bound to the concrete page views, not to layouts.account/layouts.student
     * themselves: Blade's @extends evaluates a child view's @section blocks
     * (which reference $accountMenu / $accountProfileSummary) before the
     * parent layout is resolved, so a composer registered only on the parent
     * layout name would fire too late. Composing the child view makes the
     * data available from the start of that view's render, which then
     * flows into the parent layout via @extends as normal.
     *
     * Matched by wildcard so a new Account/Student Portal page picks up
     * $accountMenu / $accountProfileSummary automatically the moment its
     * view lives under 'dashboard.*' or 'student.*' — no per-page
     * registration step to forget (this list previously had to be
     * edited by hand for every new page, which silently broke the
     * sidebar on any page whose view name was never added).
     *
     * 'profile.show' stays explicit rather than 'profile.*', since
     * 'profile.public' (the public-facing instructor profile) must NOT
     * receive the authenticated account menu.
     */
    private function registerViewComposers(): void
    {
        View::composer([
            'dashboard.*',
            'instructor.*',
            'student.*',
            'profile.show',
            'booking.manage',
        ], AccountPortalComposer::class);

        // The <html> theme class lives on the base shell. Only Account Portal
        // pages (those yielding 'portal-shell') honour it; public pages keep
        // their fixed styling. Resolution stays inside ThemeResolver.
        View::composer('layouts.frontend', function (\Illuminate\View\View $view): void {
            $resolver = app(ThemeResolver::class);
            $user = auth()->user();

            $view->with([
                'portalTheme' => $resolver->resolve($user)->value,
                'portalThemeHtmlClass' => $resolver->htmlClass($user),
            ]);
        });
    }
}
