<?php

declare(strict_types=1);

namespace App\Filament\Navigation;

/**
 * Builds the "Section" prefix shared by every admin breadcrumb trail.
 *
 * Filament's own breadcrumb arrays are `array<int|string, string>`: a
 * string key renders as a link, an int key renders as plain text (see
 * `vendor/filament/support/resources/views/components/breadcrumbs.blade.php`).
 * Every method here therefore returns int-keyed entries only — this
 * resolver never invents a link to a destination that doesn't have one
 * (no top-level "section" or settings "subgroup" page exists today), it
 * only supplies the plain-text label Stage 2's breadcrumb standard calls
 * for. Existing string-keyed (linked) entries from a page's own trail are
 * preserved untouched.
 */
final class BreadcrumbResolver
{
    /**
     * Prepends a plain-text "Section" crumb in front of a resource page's
     * own breadcrumb trail (which already contains the correct linked
     * List → Create/Edit entries from Filament itself). Returns the
     * trail unchanged if no section label is known, so a resource with
     * no NavigationRegistry entry degrades to today's behavior rather
     * than showing a blank leading crumb.
     *
     * @param  array<int|string, string>  $trail
     * @return array<int|string, string>
     */
    public static function prependSection(array $trail, ?string $sectionLabel): array
    {
        if (blank($sectionLabel)) {
            return $trail;
        }

        return [$sectionLabel, ...$trail];
    }

    /**
     * Builds a "Settings → Subgroup → Page" style trail for standalone
     * pages (Settings, Security) that get no breadcrumbs from Filament
     * by default. Any missing segment (no subgroup registered, no
     * current-page label) is dropped rather than rendered blank, and
     * every segment is plain text since none of these have a landing
     * page of their own to link to yet.
     *
     * @return array<int, string>
     */
    public static function forSettingsPage(?string $section, ?string $subgroup, ?string $currentLabel): array
    {
        return array_values(array_filter(
            [$section, $subgroup, $currentLabel],
            static fn (?string $segment): bool => filled($segment),
        ));
    }
}
