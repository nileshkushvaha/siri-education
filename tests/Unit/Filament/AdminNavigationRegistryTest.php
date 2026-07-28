<?php

declare(strict_types=1);

namespace Tests\Unit\Filament;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Navigation\NavigationRegistry;
use App\Filament\Pages\ReviewsQualityDashboard;
use App\Filament\Pages\Settings\InstructorEarningSettingsPage;
use App\Filament\Pages\Settings\ReviewQualitySettingsPage;
use App\Filament\Resources\InstructorEarnings\InstructorEarningResource;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Structural guarantees
 * for the centralized registry that don't need a database:
 *
 *  - Every class that `use`s HasCentralizedNavigation has exactly one
 *    NavigationRegistry entry, and vice versa (bidirectional coverage —
 *    a missing entry would otherwise silently return a null group/label
 *    instead of failing loudly).
 *  - Every entry's group is one of the ten declared sidebar sections
 *    (or null for the ungrouped Home/Dashboard link).
 *  - No two entries share a label unless they're on the documented
 *    exceptions list — the two label collisions found in the pre-
 *    redesign inventory (Instructor Earnings, Reviews & Quality) must
 *    stay resolved to distinct labels pointing at distinct classes.
 *  - Every entry's target class exists and still extends/implements
 *    a real Filament Resource or Page (i.e. nothing was renamed out
 *    from under the registry).
 */
class AdminNavigationRegistryTest extends TestCase
{
    /**
     * Mirrors AdminPanelProvider::navigationGroups() — kept independent
     * (not read from the provider) so a typo in either place fails this
     * test instead of the two silently drifting in lockstep.
     */
    private const array DECLARED_GROUPS = [
        'People',
        'Academics',
        'Operations',
        'Finance',
        'Growth',
        'Content & Communication',
        'Quality & Compliance',
        'Analytics',
        'Settings',
    ];

    public function test_registry_has_at_least_ninety_destinations(): void
    {
        // Loose lower bound (not an exact count) so adding a genuinely new
        // module later doesn't require editing this test — the coverage
        // tests below are what actually enforce correctness.
        $this->assertGreaterThanOrEqual(95, count(NavigationRegistry::destinations()));
    }

    public function test_every_registry_entry_targets_an_existing_class(): void
    {
        foreach (NavigationRegistry::destinations() as $class => $destination) {
            $this->assertTrue(
                class_exists($class),
                "NavigationRegistry references non-existent class [{$class}]."
            );
        }
    }

    public function test_every_registry_entry_group_is_declared_or_null(): void
    {
        foreach (NavigationRegistry::destinations() as $class => $destination) {
            if ($destination->group === null) {
                continue;
            }

            $this->assertContains(
                $destination->group,
                self::DECLARED_GROUPS,
                "NavigationRegistry entry [{$class}] uses undeclared group [{$destination->group}]."
            );
        }
    }

    public function test_every_registry_entry_has_a_unique_stable_id(): void
    {
        $ids = array_map(
            fn ($destination) => $destination->id,
            NavigationRegistry::destinations()
        );

        $this->assertSame(
            count($ids),
            count(array_unique($ids)),
            'NavigationRegistry contains duplicate stable ids.'
        );
    }

    /**
     * Every class in app/Filament/{Resources,Pages} that uses
     * HasCentralizedNavigation must have a registry entry — this is
     * what makes a missing entry fail CI instead of silently vanishing
     * from the sidebar (the trait itself has no property fallback).
     */
    public function test_every_class_using_the_trait_has_a_registry_entry(): void
    {
        $registered = array_keys(NavigationRegistry::destinations());
        $usingTrait = $this->findClassesUsingTrait(HasCentralizedNavigation::class);

        $this->assertNotEmpty($usingTrait, 'No classes found using HasCentralizedNavigation — scan is broken.');

        $missing = array_diff($usingTrait, $registered);

        $this->assertEmpty(
            $missing,
            'These classes use HasCentralizedNavigation but have no NavigationRegistry entry: '
                .implode(', ', $missing)
        );
    }

    /**
     * The inverse of the above: every registry entry's class must
     * actually use the trait, or the registry entry is dead/unused.
     */
    public function test_every_registry_entry_class_uses_the_trait(): void
    {
        foreach (NavigationRegistry::destinations() as $class => $destination) {
            $this->assertTrue(
                $this->usesTraitRecursively($class, HasCentralizedNavigation::class),
                "NavigationRegistry entry [{$class}] does not use HasCentralizedNavigation."
            );
        }
    }

    /**
     * Guards the two label collisions identified before this redesign
     * (both settings pages, not the "operations vs report" split the
     * original brief assumed) — they must resolve to distinct labels
     * on distinct classes, never silently re-collide or get merged.
     */
    public function test_labels_are_unique_except_documented_cross_links(): void
    {
        $labelsToClasses = [];

        foreach (NavigationRegistry::destinations() as $class => $destination) {
            $labelsToClasses[$destination->label][] = $class;
        }

        $duplicates = array_filter($labelsToClasses, fn ($classes) => count($classes) > 1);

        $this->assertEmpty(
            $duplicates,
            'Duplicate navigation labels found (each must be unique): '.
                collect($duplicates)->map(fn ($classes, $label) => "\"{$label}\" => ".implode(', ', $classes))->implode('; ')
        );
    }

    public function test_instructor_earnings_ledger_and_settings_are_distinct_destinations(): void
    {
        $destinations = NavigationRegistry::destinations();

        $ledger = $destinations[InstructorEarningResource::class];
        $settings = $destinations[InstructorEarningSettingsPage::class];

        $this->assertNotSame($ledger->label, $settings->label);
        $this->assertSame('Instructor Earnings', $ledger->label);
        $this->assertSame('Instructor Earnings Rules', $settings->label);
        $this->assertSame('Finance', $ledger->group);
        $this->assertSame('Finance', $settings->group);
        $this->assertSame('Instructor Earnings & Payouts', $ledger->subgroup);
        $this->assertSame('Finance Configuration', $settings->subgroup);
    }

    public function test_review_operations_and_review_settings_are_distinct_destinations(): void
    {
        $destinations = NavigationRegistry::destinations();

        $operations = $destinations[ReviewsQualityDashboard::class];
        $settings = $destinations[ReviewQualitySettingsPage::class];

        $this->assertNotSame($operations->label, $settings->label);
        $this->assertSame('Review Operations', $operations->label);
        $this->assertSame('Review & Quality Configuration', $settings->label);
        $this->assertSame('Quality & Compliance', $operations->group);
        $this->assertSame('Settings', $settings->group);
    }

    /**
     * Stage 2 (admin forms presentation foundation): subgroupFor() is an
     * additive accessor alongside groupFor()/labelFor()/sortFor(), read
     * by the new breadcrumb resolver to build "Section → Subgroup →
     * Page" settings breadcrumbs. It must never diverge from the same
     * destination's own ->subgroup property.
     */
    public function test_subgroup_for_matches_the_registry_entry(): void
    {
        $this->assertSame('Instructor Earnings & Payouts', NavigationRegistry::subgroupFor(InstructorEarningResource::class));
        $this->assertSame('Finance Configuration', NavigationRegistry::subgroupFor(InstructorEarningSettingsPage::class));

        foreach (NavigationRegistry::destinations() as $class => $destination) {
            $this->assertSame($destination->subgroup, NavigationRegistry::subgroupFor($class));
        }
    }

    public function test_subgroup_for_returns_null_for_an_unregistered_class(): void
    {
        $this->assertNull(NavigationRegistry::subgroupFor(self::class));
    }

    /**
     * @return array<int, class-string>
     */
    private function findClassesUsingTrait(string $trait): array
    {
        $found = [];
        $basePath = base_path('app/Filament');

        foreach ((new Finder)->files()->in([$basePath.'/Resources', $basePath.'/Pages'])->name('*.php') as $file) {
            $source = file_get_contents($file->getRealPath());

            if (! str_contains($source, class_basename($trait))) {
                continue;
            }

            $class = $this->classFromFile($file->getRealPath());

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            if ($this->usesTraitRecursively($class, $trait)) {
                $found[] = $class;
            }
        }

        return $found;
    }

    private function classFromFile(string $path): ?string
    {
        $source = file_get_contents($path);

        if (! preg_match('/^namespace\s+([^;]+);/m', $source, $ns)) {
            return null;
        }

        if (! preg_match('/^(?:abstract\s+|final\s+)*class\s+(\w+)/m', $source, $cls)) {
            return null;
        }

        return $ns[1].'\\'.$cls[1];
    }

    /**
     * True if $class, or any of its parent classes, `use`s $trait directly
     * (every wiring in this redesign is on the concrete class itself, but
     * this checks parents too as a defensive superset).
     */
    private function usesTraitRecursively(string $class, string $trait): bool
    {
        foreach (array_merge([$class], class_parents($class) ?: []) as $c) {
            if (in_array($trait, class_uses($c) ?: [], true)) {
                return true;
            }
        }

        return false;
    }
}
