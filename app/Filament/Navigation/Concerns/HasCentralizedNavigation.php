<?php

declare(strict_types=1);

namespace App\Filament\Navigation\Concerns;

use App\Filament\Navigation\NavigationRegistry;
use UnitEnum;

/**
 * Wires a Resource or Page into the centralized NavigationRegistry for
 * group/label/sort. Everything else — icon, badge, canAccess(),
 * shouldRegisterNavigation(), Policies — is untouched and stays exactly
 * as already implemented on the class.
 *
 * Deliberately does not fall back to the class's own $navigationGroup /
 * $navigationLabel / $navigationSort properties: a trait can't see
 * properties declared on the classes that use it (each Resource/Page
 * declares its own), so referencing them here would be an undefined-
 * property access as far as static analysis is concerned. Every class
 * that uses this trait is required to have a NavigationRegistry entry;
 * NavigationRegistryCoverageTest enforces that so a missing entry fails
 * the test suite instead of silently vanishing from the sidebar.
 */
trait HasCentralizedNavigation
{
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return NavigationRegistry::groupFor(static::class);
    }

    public static function getNavigationLabel(): string
    {
        return NavigationRegistry::labelFor(static::class) ?? class_basename(static::class);
    }

    public static function getNavigationSort(): ?int
    {
        return NavigationRegistry::sortFor(static::class);
    }
}
