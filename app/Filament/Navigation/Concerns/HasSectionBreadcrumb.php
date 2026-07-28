<?php

declare(strict_types=1);

namespace App\Filament\Navigation\Concerns;

use App\Filament\Navigation\BreadcrumbResolver;
use App\Filament\Navigation\NavigationRegistry;

/**
 * Adds the resource's centralized navigation Section as a plain-text
 * leading breadcrumb, in front of Filament's own List → Create/Edit
 * trail (`parent::getBreadcrumbs()`, untouched). For use on Resource
 * Create/Edit/List/View pages once a page is deliberately opted in —
 * see docs/architecture/admin-forms-presentation-conventions.md.
 *
 * Not applied to any page yet; Stage 4's domain batches adopt it
 * resource by resource.
 */
trait HasSectionBreadcrumb
{
    public function getBreadcrumbs(): array
    {
        return BreadcrumbResolver::prependSection(
            parent::getBreadcrumbs(),
            NavigationRegistry::groupFor(static::getResource()),
        );
    }
}
