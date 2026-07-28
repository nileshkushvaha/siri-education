<?php

declare(strict_types=1);

namespace Tests\Unit\Filament;

use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Tests\TestCase;

/**
 * Stage 2 presentation-foundation guarantee that doesn't need a
 * database: the admin panel stays full-width. (The "Create & create
 * another" removal is asserted against real Create pages in
 * tests/Feature/Filament/CreateAnotherActionRemovedTest.php instead —
 * that exercises the actual rendered behavior rather than reading the
 * underlying static property directly.)
 */
class AdminPanelPresentationConfigurationTest extends TestCase
{
    public function test_admin_panel_keeps_full_width_content(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertSame(Width::Full, $panel->getMaxContentWidth());
    }
}
