<?php

declare(strict_types=1);

namespace App\Filament\Navigation;

/**
 * One entry in the centralized navigation registry (see NavigationRegistry).
 *
 * This is metadata only — it never grants or removes access. Every
 * destination's `canAccess()` / `shouldRegisterNavigation()` / Policy
 * stays exactly as already implemented on the Resource or Page class;
 * this DTO only decides where the destination sits, what it's called,
 * and in what order, once that class has already decided it's visible.
 */
final readonly class NavigationDestination
{
    /**
     * @param  string  $id  Stable identifier, never reused/renamed once assigned —
     *                      safe to persist in favorites/recents when that lands.
     * @param  string  $label  Current display label (may differ from the class's
     *                         own $navigationLabel — this one wins).
     * @param  string|null  $group  One of the 10 top-level sidebar sections. Null
     *                              means "no group header" (Home/Dashboard only).
     * @param  string|null  $subgroup  Contextual sub-heading within the section
     *                                 (e.g. "Instructors", "Finance Configuration").
     *                                 Currently informational only (reserved for
     *                                 breadcrumbs/search path display and section
     *                                 landing pages) — it does not change how
     *                                 Filament renders the flat two-level sidebar today.
     * @param  int|null  $sort  Order within the group.
     * @param  string|null  $crossLinkedFrom  Another section this destination is
     *                                        also contextually relevant to. Documents
     *                                        a cross-link, reserved for future UI; never
     *                                        creates a second navigable route for the same page.
     * @param  string|null  $previousGroup  Prior navigationGroup value, for the
     *                                      old-to-new migration mapping/report.
     * @param  string|null  $previousLabel  Prior navigationLabel value, for the
     *                                      old-to-new migration mapping/report.
     */
    public function __construct(
        public string $id,
        public string $label,
        public ?string $group,
        public ?string $subgroup,
        public ?int $sort,
        public ?string $crossLinkedFrom = null,
        public ?string $previousGroup = null,
        public ?string $previousLabel = null,
    ) {}
}
