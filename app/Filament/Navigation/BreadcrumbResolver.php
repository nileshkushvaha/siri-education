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
     * Builds a "Section → Page" trail for standalone pages (Settings,
     * Security) that get no breadcrumbs from Filament by default.
     *
     * Deliberately TWO segments, not three. NavigationRegistry also carries a
     * `subgroup`, and this used to render it in the middle — producing trails
     * like "Settings → Platform → General Settings". But subgroup is
     * informational metadata: HasCentralizedNavigation feeds only `group`,
     * `label` and `sort` to Filament, so no "Platform" level exists in the
     * sidebar, at a URL, or anywhere else a person could navigate to. A
     * breadcrumb naming a level that does not exist tells the reader they are
     * one click from somewhere that was never there.
     *
     * Section mirrors the sidebar group, so the trail always describes a real
     * path. A missing segment is dropped rather than rendered blank, and both
     * are plain text because neither a group nor these pages' sections have a
     * landing page to link to.
     *
     * @return array<int, string>
     */
    public static function forSettingsPage(?string $section, ?string $currentLabel): array
    {
        return array_values(array_filter(
            [$section, $currentLabel],
            static fn (?string $segment): bool => filled($segment),
        ));
    }
}
