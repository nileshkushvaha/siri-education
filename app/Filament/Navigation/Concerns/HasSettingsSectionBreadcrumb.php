<?php

declare(strict_types=1);

namespace App\Filament\Navigation\Concerns;

use App\Filament\Navigation\BreadcrumbResolver;
use App\Filament\Navigation\NavigationRegistry;
use Filament\Pages\BasePage;

/**
 * @mixin BasePage
 *
 * Builds a "Settings → Subgroup → Page" breadcrumb trail for standalone
 * pages (Settings, Security) that otherwise render no breadcrumbs at all
 * by Filament's default (`Filament\Pages\Page::getBreadcrumbs()` returns
 * `[]` unless clustered). Section and Subgroup come from the same
 * centralized NavigationRegistry entry every one of these pages already
 * has via HasCentralizedNavigation, so a page adopting this trait can
 * never point its own breadcrumb at the wrong subgroup — there is only
 * one registry entry to read from.
 *
 * Not applied to any page yet; each settings/security page adopts it
 * individually in a later stage — see
 * docs/architecture/admin-forms-presentation-conventions.md.
 */
trait HasSettingsSectionBreadcrumb
{
    public function getBreadcrumbs(): array
    {
        $heading = $this->getHeading();

        return BreadcrumbResolver::forSettingsPage(
            NavigationRegistry::groupFor(static::class),
            NavigationRegistry::subgroupFor(static::class),
            is_string($heading) ? $heading : null,
        );
    }
}
