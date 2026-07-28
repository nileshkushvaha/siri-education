<?php

declare(strict_types=1);

namespace Tests\Unit\Filament;

use App\Filament\Navigation\BreadcrumbResolver;
use Tests\TestCase;

/**
 * Pure-function coverage for the Stage 2 shared breadcrumb resolver.
 * No page is mounted here — the resolver's array-building logic is
 * fully exercised without needing a database or a Livewire lifecycle,
 * and the two thin traits that adopt it (HasSectionBreadcrumb,
 * HasSettingsSectionBreadcrumb) are one-line delegations to it.
 */
class BreadcrumbResolverTest extends TestCase
{
    public function test_prepend_section_adds_a_plain_text_leading_crumb(): void
    {
        $trail = ['/admin/faq/faq-categories' => 'FAQ Categories', 'Create'];

        $result = BreadcrumbResolver::prependSection($trail, 'Content & Communication');

        $this->assertSame(
            ['Content & Communication', '/admin/faq/faq-categories' => 'FAQ Categories', 'Create'],
            $result,
        );
    }

    public function test_prepend_section_leaves_the_trail_untouched_when_no_section_is_known(): void
    {
        $trail = ['/admin/faq/faq-categories' => 'FAQ Categories', 'Create'];

        $this->assertSame($trail, BreadcrumbResolver::prependSection($trail, null));
        $this->assertSame($trail, BreadcrumbResolver::prependSection($trail, ''));
    }

    public function test_prepended_section_crumb_is_plain_text_not_a_link(): void
    {
        $result = BreadcrumbResolver::prependSection(['Create'], 'Finance');

        // Filament renders int-keyed entries as plain text and string-keyed
        // entries as links (breadcrumbs.blade.php) — the section crumb must
        // stay int-keyed since no section landing page exists to link to.
        $this->assertArrayHasKey(0, $result);
        $this->assertSame('Finance', $result[0]);
    }

    public function test_settings_page_trail_orders_section_then_subgroup_then_page(): void
    {
        $result = BreadcrumbResolver::forSettingsPage('Finance', 'Instructor Earnings & Payouts', 'Instructor Earning Settings');

        $this->assertSame(
            ['Finance', 'Instructor Earnings & Payouts', 'Instructor Earning Settings'],
            $result,
        );
    }

    public function test_settings_page_trail_drops_missing_segments_instead_of_rendering_blank(): void
    {
        $this->assertSame(
            ['Settings', 'Payment Configuration'],
            BreadcrumbResolver::forSettingsPage('Settings', null, 'Payment Configuration'),
        );

        $this->assertSame(
            ['Payment Configuration'],
            BreadcrumbResolver::forSettingsPage(null, null, 'Payment Configuration'),
        );

        $this->assertSame(
            [],
            BreadcrumbResolver::forSettingsPage(null, null, null),
        );
    }

    public function test_settings_page_trail_entries_are_never_links(): void
    {
        $result = BreadcrumbResolver::forSettingsPage('Settings', 'Payment', 'Gateways');

        $this->assertSame([0, 1, 2], array_keys($result));
    }

    /**
     * Filament's own EditRecord already inserts the record title into the
     * trail automatically (Concerns\InteractsWithRecord::getBreadcrumbs())
     * — prependSection() must not duplicate it. This is exactly the bug an
     * earlier version of this resolver introduced (a since-removed
     * insertRecordTitle() helper) before being caught on a real page.
     */
    public function test_prepend_section_does_not_duplicate_an_edit_pages_own_record_crumb(): void
    {
        $editTrailWithRecordAlreadyIncluded = [
            '/admin/bookings' => 'Bookings',
            'BK-BH6ANJ3UEW',
            'Edit',
        ];

        $result = BreadcrumbResolver::prependSection($editTrailWithRecordAlreadyIncluded, 'Operations');

        $this->assertSame(
            ['Operations', '/admin/bookings' => 'Bookings', 'BK-BH6ANJ3UEW', 'Edit'],
            $result,
        );
        $this->assertSame(1, substr_count(implode('|', $result), 'BK-BH6ANJ3UEW'));
    }
}
