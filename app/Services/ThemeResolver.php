<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ThemePreference;
use App\Models\User;

/**
 * Single source of truth for which colour theme the Frontend Portal
 * (student / instructor pages under layouts.account) renders in.
 *
 * Resolution order: the user's stored preference, then the product
 * default (light). Layouts, composers and Blade components must call
 * this class instead of reading user_profiles.theme_preference directly.
 *
 * Portal chrome only: the public marketing site keeps its own fixed
 * styling and the Filament admin panel manages its own dark mode.
 */
final class ThemeResolver
{
    public function resolve(?User $user): ThemePreference
    {
        return $user?->profile?->theme_preference ?? ThemePreference::DEFAULT;
    }

    /**
     * Class list for the <html> element. "system" leaves the decision to
     * the inline bootstrap script in layouts.frontend, which reads
     * prefers-color-scheme before first paint so there is no flash.
     */
    public function htmlClass(?User $user): string
    {
        return match ($this->resolve($user)) {
            ThemePreference::Dark => 'dark',
            default => '',
        };
    }
}
