<?php

declare(strict_types=1);

namespace App\Filament\Support\Presentation;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * The one deterministic "Back" affordance for admin pages, matching the
 * pattern already hand-written on ActivityLog/LoginHistory's View pages
 * (`Action::make('back')->icon('heroicon-o-arrow-left')->color('gray')`)
 * rather than inventing a new visual language for it.
 *
 * Deliberately not a Filament macro: `Action::make()` already accepts
 * everything needed (label, icon, color, url), so wrapping it in one
 * factory method is the smallest safe extension point — no class needs
 * to support a macro that doesn't already exist on it.
 *
 * Returns null when no destination is known so a page can compose it as
 * `...(BackAction::make($url) ? [BackAction::make($url)] : [])` and
 * simply omit Back rather than ever linking somewhere unauthorized.
 *
 * Not wired into any page yet — see
 * docs/architecture/admin-forms-presentation-conventions.md for the
 * adoption plan.
 */
final class BackAction
{
    public static function make(?string $url, string $label = 'Back', string $key = 'back'): ?Action
    {
        if (blank($url)) {
            return null;
        }

        return Action::make($key)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowLeft)
            ->color('gray')
            ->url($url);
    }

    /**
     * Back to a resource's own index page — the common case. Checks
     * `canViewAny()` itself so a page never links Back to an index the
     * current user isn't authorized to see; every call site gets this
     * check for free instead of repeating the same ternary.
     *
     * @param  class-string  $resourceClass
     */
    public static function toResourceIndex(string $resourceClass, string $label): ?Action
    {
        if (! $resourceClass::canViewAny()) {
            return null;
        }

        return self::make($resourceClass::getUrl('index'), $label);
    }
}
