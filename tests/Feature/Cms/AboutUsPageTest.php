<?php

namespace Tests\Feature\Cms;

use App\Models\Page;
use Database\Seeders\AboutUsPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AboutUsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_about_page_uses_srs_aligned_public_content(): void
    {
        $this->seed(AboutUsPageSeeder::class);

        $this->get('/about-us')
            ->assertOk()
            ->assertSee('Learning works better when every step stays connected')
            ->assertSee('Built for the complete learning relationship')
            ->assertSee('Student-first by design')
            ->assertSee('Trust is operational')
            ->assertSee('/become-instructor', false)
            ->assertDontSee('Laravel Horizon');
    }

    public function test_cms_layout_update_cannot_strip_structured_page_design(): void
    {
        $this->seed(AboutUsPageSeeder::class);

        $page = Page::query()->where('slug', 'about-us')->firstOrFail();
        $originalContent = $page->content;

        $page->update([
            'layout' => 'full-width',
            'content' => '<p>Why we exist</p><h2>Learning works better when every step stays connected</h2>',
        ]);

        $page->refresh();

        $this->assertSame('full-width', $page->layout);
        $this->assertSame($originalContent, $page->content);
        $this->assertStringContainsString('data-cms-structured-page', $page->content);
        $this->assertStringContainsString('class="about-page"', $page->content);
    }
}
