<?php

namespace Tests\Feature;

use App\Enums\BlockType;
use App\Services\BlockContentHydrator;
use App\Services\BlockRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeroCarouselBlockTest extends TestCase
{
    use RefreshDatabase;

    private function slides(): array
    {
        return [
            [
                'tab_label' => 'One-to-One Tutoring',
                'rotating_text' => 'personalised 1-on-1 lessons',
                'image' => null,
                'primary_button_text' => 'Find a Tutor',
                'primary_button_link' => '/register',
            ],
            [
                'tab_label' => 'Exam Preparation',
                'rotating_text' => 'focused competitive exam prep',
                'image' => null,
            ],
        ];
    }

    public function test_it_renders_every_slide_label_and_rotating_line(): void
    {
        $html = (new BlockRenderer)->renderBlock(BlockType::HeroCarousel, [
            'prefix_text' => 'Learn faster with',
            'suffix_text' => 'from verified experts.',
            'footnote' => 'Trusted by 10,000+ students',
            'slides' => $this->slides(),
            'autoplay' => true,
            'interval' => 5000,
            'show_arrows' => true,
        ])->render();

        $this->assertStringContainsString('Learn faster with', $html);
        $this->assertStringContainsString('from verified experts.', $html);
        $this->assertStringContainsString('Trusted by 10,000+ students', $html);

        foreach ($this->slides() as $slide) {
            $this->assertStringContainsString($slide['tab_label'], $html);
            $this->assertStringContainsString($slide['rotating_text'], $html);
        }
    }

    public function test_it_renders_nothing_when_no_slides_are_configured(): void
    {
        $html = (new BlockRenderer)->renderBlock(BlockType::HeroCarousel, [
            'prefix_text' => 'Learn faster with',
            'slides' => [],
        ])->render();

        $this->assertSame('', trim($html));
    }

    public function test_it_skips_slides_without_a_rotating_line(): void
    {
        $html = (new BlockRenderer)->renderBlock(BlockType::HeroCarousel, [
            'slides' => [
                ['tab_label' => 'Valid Slide', 'rotating_text' => 'a real line'],
                ['tab_label' => 'Empty Slide', 'rotating_text' => ''],
            ],
        ])->render();

        $this->assertStringContainsString('Valid Slide', $html);
        $this->assertStringNotContainsString('Empty Slide', $html);
    }

    public function test_default_homepage_template_renders_the_carousel(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('hero-carousel', false);
        foreach ([
            'One-to-One Tutoring',
            'Exam Preparation',
            'Flexible Scheduling',
            'Homework Support',
            'Progress Tracking',
        ] as $tabLabel) {
            $response->assertSee($tabLabel);
        }

        $response->assertSee('personalised 1-on-1 lessons');
        $response->assertSee('progress you can actually see');
    }

    public function test_an_admin_uploaded_photo_resolves_to_a_web_servable_url(): void
    {
        $html = (new BlockRenderer)->renderBlock(BlockType::HeroCarousel, [
            'slides' => [[
                'tab_label' => 'One',
                'rotating_text' => 'first line',
                'image' => 'blocks/hero-carousel/subject.png',
            ]],
        ])->render();

        // Uploads are pinned to the `public` disk, served through the /storage symlink.
        // The default disk is `local` (storage/app/private) and is not web-servable.
        $this->assertStringContainsString('/storage/blocks/hero-carousel/subject.png', $html);
    }

    public function test_a_slide_photo_that_is_not_on_disk_yet_is_skipped_rather_than_broken(): void
    {
        $missing = '/images/hero/definitely-not-uploaded-yet.png';
        $this->assertFileDoesNotExist(public_path(ltrim($missing, '/')));

        $html = (new BlockRenderer)->renderBlock(BlockType::HeroCarousel, [
            'slides' => [[
                'tab_label' => 'One',
                'rotating_text' => 'first line',
                'image' => $missing,
                'badge_title' => 'Exam Preparation',
                'highlights' => ['Mock Tests'],
            ]],
        ])->render();

        // The headline still renders; the subject stage and its overlays stay out.
        $this->assertStringContainsString('first line', $html);
        $this->assertStringNotContainsString($missing, $html);
        $this->assertStringNotContainsString('Exam Preparation', $html);
        $this->assertStringNotContainsString('Mock Tests', $html);
    }

    public function test_a_slide_photo_on_disk_renders_the_full_subject_treatment(): void
    {
        $relative = 'images/hero/test-subject.png';
        $absolute = public_path($relative);
        file_put_contents($absolute, 'png');

        try {
            $html = (new BlockRenderer)->renderBlock(BlockType::HeroCarousel, [
                'slides' => [[
                    'tab_label' => 'One',
                    'rotating_text' => 'first line',
                    'image' => "/{$relative}",
                    'badge_title' => 'Exam Preparation',
                    'badge_subtitle' => 'Powered by SIRI',
                    'highlights' => ['Mock Tests', 'Weak Areas Fixed', 'Scores Improved'],
                ]],
            ])->render();

            $this->assertStringContainsString("/{$relative}", $html);
            $this->assertStringContainsString('Exam Preparation', $html);
            $this->assertStringContainsString('Powered by SIRI', $html);
            $this->assertStringContainsString('Scores Improved', $html);
            $this->assertStringContainsString('clip-path:polygon', $html);
        } finally {
            @unlink($absolute);
        }
    }

    public function test_every_rotating_line_is_rendered_without_a_clipping_wrapper(): void
    {
        $html = (new BlockRenderer)->renderBlock(BlockType::HeroCarousel, [
            'slides' => [
                ['tab_label' => 'Short', 'rotating_text' => 'short line'],
                ['tab_label' => 'Long', 'rotating_text' => 'a considerably longer line that will wrap across two rows'],
            ],
        ])->render();

        // Each line shares one grid cell; a fixed-height frame would crop wrapped copy
        // outside the .text-grad paint box and render it invisible.
        $this->assertStringContainsString('a considerably longer line that will wrap across two rows', $html);
        $this->assertStringNotContainsString('h-[1.15em]', $html);
        $this->assertStringContainsString('col-start-1 row-start-1', $html);
    }

    public function test_rotating_lines_drive_visibility_through_inline_style_only(): void
    {
        $html = (new BlockRenderer)->renderBlock(BlockType::HeroCarousel, [
            'slides' => [
                ['tab_label' => 'One', 'rotating_text' => 'first line'],
                ['tab_label' => 'Two', 'rotating_text' => 'second line'],
            ],
        ])->render();

        // A hidden inline style outranks any utility class Alpine toggles, which would pin
        // every non-first line transparent forever. Both the pre-boot state and the bound
        // state must therefore be written to the style attribute.
        $this->assertStringContainsString(':style="active === 1', $html);
        $this->assertStringNotContainsString(":class=\"active === 1 ? 'translate-y-0 opacity-100'", $html);

        // The two-tone headline uses the theme gradient, which paints via
        // background-clip:text. That is only safe because the grid frame has no fixed
        // height for wrapped copy to overflow.
        $this->assertStringContainsString('text-grad', $html);
        $this->assertStringNotContainsString('h-[1.15em]', $html);
    }

    public function test_headline_type_matches_the_razorpay_hero_scale(): void
    {
        $html = (new BlockRenderer)->renderBlock(BlockType::HeroCarousel, [
            'prefix_text' => 'Learn faster with',
            'slides' => [['tab_label' => 'One', 'rotating_text' => 'first line']],
        ])->render();

        $this->assertStringContainsString('font-medium', $html);
        $this->assertStringContainsString('tracking-[-0.02em]', $html);
        $this->assertStringContainsString('leading-[1.15]', $html);
        $this->assertStringNotContainsString('font-black', $html);
    }

    public function test_default_homepage_renders_the_why_study_cards_under_the_hero(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Why study with us?');

        foreach ([
            'Proven way to improve marks',
            'Personalised attention',
            'Subject specific courses',
            'Dedicated student account managers',
        ] as $cardTitle) {
            $response->assertSee($cardTitle);
        }
    }

    public function test_hydrator_fills_defaults_for_missing_keys(): void
    {
        $hydrated = BlockContentHydrator::hydrate(BlockType::HeroCarousel, []);

        $this->assertSame([], $hydrated['slides']);
        $this->assertSame('', $hydrated['prefix_text']);
        $this->assertSame('', $hydrated['suffix_text']);
        $this->assertSame('', $hydrated['footnote']);
        $this->assertTrue($hydrated['autoplay']);
        $this->assertSame(5000, $hydrated['interval']);
        $this->assertTrue($hydrated['show_arrows']);
    }

    public function test_hydrator_preserves_authored_content(): void
    {
        $hydrated = BlockContentHydrator::hydrate(BlockType::HeroCarousel, [
            'slides' => $this->slides(),
            'autoplay' => false,
            'interval' => 8000,
            'show_arrows' => false,
        ]);

        $this->assertCount(2, $hydrated['slides']);
        $this->assertFalse($hydrated['autoplay']);
        $this->assertSame(8000, $hydrated['interval']);
        $this->assertFalse($hydrated['show_arrows']);
    }
}
