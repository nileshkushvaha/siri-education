<?php

declare(strict_types=1);

namespace App\Filament\Navigation\Concerns;

use App\Filament\Navigation\BreadcrumbResolver;
use App\Filament\Navigation\NavigationRegistry;
use Filament\Pages\BasePage;

/**
 * @mixin BasePage
 *
 * Builds a "Section → Page" breadcrumb trail for standalone pages
 * (Settings, Security) that otherwise render no breadcrumbs at all by
 * Filament's default (`Filament\Pages\Page::getBreadcrumbs()` returns `[]`
 * unless clustered). Section comes from the same centralized
 * NavigationRegistry entry every one of these pages already has via
 * HasCentralizedNavigation, so a page adopting this trait can never point
 * its own breadcrumb at the wrong section — there is only one registry
 * entry to read from.
 *
 * The registry's `subgroup` is deliberately NOT part of the trail: it is
 * informational metadata that never reaches the sidebar, so including it
 * described a level of the UI that does not exist. See
 * BreadcrumbResolver::forSettingsPage().
 */
trait HasSettingsSectionBreadcrumb
{
    public function getBreadcrumbs(): array
    {
        $heading = $this->getHeading();

        return BreadcrumbResolver::forSettingsPage(
            NavigationRegistry::groupFor(static::class),
            is_string($heading) ? $heading : null,
        );
    }
}
