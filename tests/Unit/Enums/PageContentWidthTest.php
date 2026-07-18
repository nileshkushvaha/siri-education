<?php

namespace Tests\Unit\Enums;

use App\Enums\PageContentWidth;
use PHPUnit\Framework\TestCase;

class PageContentWidthTest extends TestCase
{
    public function test_available_widths_resolve_to_their_dynamic_tailwind_classes(): void
    {
        $default = PageContentWidth::resolve('default');
        $fullWidth = PageContentWidth::resolve('full-width');

        $this->assertSame('mx-auto max-w-7xl px-4 sm:px-6 lg:px-8', $default->contentContainerClasses());
        $this->assertSame('w-full [&_.cms-section]:px-4 sm:[&_.cms-section]:px-6 lg:[&_.cms-section]:px-8', $default->pageShellClasses());
        $this->assertSame('w-full', $fullWidth->contentContainerClasses());
        $this->assertSame('w-full [&>section>div.max-w-7xl]:max-w-none [&_.cms-section]:px-4 sm:[&_.cms-section]:px-6 lg:[&_.cms-section]:px-[max(2rem,calc((100vw-80rem)/2))]', $fullWidth->pageShellClasses());
    }

    public function test_unavailable_and_unknown_widths_fall_back_to_default(): void
    {
        $this->assertSame(PageContentWidth::Default, PageContentWidth::resolve('sidebar-left'));
        $this->assertSame(PageContentWidth::Default, PageContentWidth::resolve('unknown'));
        $this->assertSame(PageContentWidth::Default, PageContentWidth::resolve(null));
    }
}
