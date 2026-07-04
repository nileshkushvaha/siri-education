<?php

namespace Tests\Unit\Services;

use App\Content\Models\ContentBlock;
use App\Enums\BlockType;
use App\Models\Page;
use App\Services\BlockRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlockRendererTest extends TestCase
{
    use RefreshDatabase;

    private BlockRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new BlockRenderer;
    }

    public function test_render_hero_block(): void
    {
        $block = $this->createBlock(BlockType::Hero, [
            'title' => 'Welcome',
            'subtitle' => 'Test subtitle',
            'image' => null,
            'button_text' => 'Click',
            'button_link' => '/test',
            'button_style' => 'primary',
        ]);

        $html = $this->renderer->render($block);

        $this->assertStringContainsString('Welcome', $html);
        $this->assertStringContainsString('Test subtitle', $html);
        $this->assertStringContainsString('Click', $html);
    }

    public function test_render_rich_text_block(): void
    {
        $block = $this->createBlock(BlockType::RichText, [
            'text' => '<p>Rich text content</p>',
        ]);

        $html = $this->renderer->render($block);

        $this->assertStringContainsString('Rich text content', $html);
    }

    public function test_render_image_block(): void
    {
        $block = $this->createBlock(BlockType::Image, [
            'image' => '/test.jpg',
            'alt_text' => 'Test image',
            'caption' => 'Test caption',
        ]);

        $html = $this->renderer->render($block);

        $this->assertStringContainsString('/test.jpg', $html);
        $this->assertStringContainsString('Test caption', $html);
    }

    public function test_render_gallery_block(): void
    {
        $block = $this->createBlock(BlockType::Gallery, [
            'images' => [
                ['url' => '/img1.jpg', 'caption' => 'Image 1'],
                ['url' => '/img2.jpg', 'caption' => 'Image 2'],
            ],
            'columns' => 2,
        ]);

        $html = $this->renderer->render($block);

        $this->assertStringContainsString('/img1.jpg', $html);
        $this->assertStringContainsString('/img2.jpg', $html);
    }

    public function test_render_cta_block(): void
    {
        $block = $this->createBlock(BlockType::CTA, [
            'title' => 'Ready to Start?',
            'description' => 'Join us today',
            'button_text' => 'Sign Up',
            'button_link' => '/signup',
            'button_style' => 'primary',
            'backgroundColor' => '#ffffff',
        ]);

        $html = $this->renderer->render($block);

        $this->assertStringContainsString('Ready to Start?', $html);
        $this->assertStringContainsString('Join us today', $html);
        $this->assertStringContainsString('Sign Up', $html);
    }

    public function test_render_faq_block(): void
    {
        $block = $this->createBlock(BlockType::FAQ, [
            'items' => [
                ['question' => 'Q1?', 'answer' => 'A1'],
                ['question' => 'Q2?', 'answer' => 'A2'],
            ],
        ]);

        $html = $this->renderer->render($block);

        $this->assertStringContainsString('Q1?', $html);
        $this->assertStringContainsString('A1', $html);
        $this->assertStringContainsString('Q2?', $html);
    }

    public function test_render_accordion_block(): void
    {
        $block = $this->createBlock(BlockType::Accordion, [
            'items' => [
                ['title' => 'Section 1', 'content' => 'Content 1'],
                ['title' => 'Section 2', 'content' => 'Content 2'],
            ],
        ]);

        $html = $this->renderer->render($block);

        $this->assertStringContainsString('Section 1', $html);
        $this->assertStringContainsString('Content 1', $html);
    }

    public function test_render_tabs_block(): void
    {
        $block = $this->createBlock(BlockType::Tabs, [
            'items' => [
                ['label' => 'Tab 1', 'content' => 'Content 1'],
                ['label' => 'Tab 2', 'content' => 'Content 2'],
            ],
        ]);

        $html = $this->renderer->render($block);

        $this->assertStringContainsString('Tab 1', $html);
        $this->assertStringContainsString('Tab 2', $html);
    }

    public function test_render_team_block(): void
    {
        $block = $this->createBlock(BlockType::Team, [
            'title' => 'Our Team',
            'description' => 'Meet the team',
            'members' => [
                ['name' => 'John Doe', 'title' => 'CEO', 'bio' => ''],
                ['name' => 'Jane Smith', 'title' => 'CTO', 'bio' => ''],
            ],
            'columns' => 2,
        ]);

        $html = $this->renderer->render($block);

        $this->assertStringContainsString('John Doe', $html);
        $this->assertStringContainsString('Jane Smith', $html);
        $this->assertStringContainsString('CEO', $html);
    }

    public function test_render_testimonials_block(): void
    {
        $block = $this->createBlock(BlockType::Testimonials, [
            'testimonials' => [
                ['text' => 'Great product!', 'author' => 'John'],
                ['text' => 'Amazing service!', 'author' => 'Jane'],
            ],
            'columns' => 2,
        ]);

        $html = $this->renderer->render($block);

        $this->assertStringContainsString('Great product!', $html);
        $this->assertStringContainsString('Amazing service!', $html);
    }

    public function test_render_features_block(): void
    {
        $block = $this->createBlock(BlockType::Features, [
            'title' => 'Platform Features',
            'features' => [
                ['title' => 'Reusable Blocks', 'description' => 'Render from CMS data'],
            ],
            'columns' => 3,
        ]);

        $html = $this->renderer->render($block);

        $this->assertStringContainsString('Platform Features', $html);
        $this->assertStringContainsString('Reusable Blocks', $html);
        $this->assertStringContainsString('Render from CMS data', $html);
    }

    public function test_render_pricing_block(): void
    {
        $block = $this->createBlock(BlockType::Pricing, [
            'title' => 'Plans',
            'plans' => [
                [
                    'name' => 'Team',
                    'price' => '$99',
                    'features' => ['CMS rendering'],
                    'button_text' => 'Choose Plan',
                    'button_link' => '/pricing/team',
                ],
            ],
            'columns' => 3,
        ]);

        $html = $this->renderer->render($block);

        $this->assertStringContainsString('Plans', $html);
        $this->assertStringContainsString('Team', $html);
        $this->assertStringContainsString('CMS rendering', $html);
        $this->assertStringContainsString('Choose Plan', $html);
    }

    public function test_render_featured_courses_block(): void
    {
        $block = $this->createBlock(BlockType::FeaturedCourses, [
            'title' => 'Featured Courses',
            'courses' => [
                ['title' => 'CMS Authored Course', 'description' => 'Managed from page blocks'],
            ],
            'columns' => 3,
        ]);

        $html = $this->renderer->render($block);

        $this->assertStringContainsString('Featured Courses', $html);
        $this->assertStringContainsString('CMS Authored Course', $html);
    }

    public function test_render_featured_teachers_block(): void
    {
        $block = $this->createBlock(BlockType::FeaturedTeachers, [
            'title' => 'Featured Teachers',
            'limit' => 4,
            'columns' => 4,
        ]);

        $html = $this->renderer->render($block);

        $this->assertStringContainsString('Featured Teachers', $html);
    }

    public function test_render_newsletter_block(): void
    {
        $block = $this->createBlock(BlockType::Newsletter, [
            'title' => 'Newsletter',
            'email_label' => 'Email',
            'button_text' => 'Subscribe',
        ]);

        $html = $this->renderer->render($block);

        $this->assertStringContainsString('Newsletter', $html);
        $this->assertStringContainsString('Subscribe', $html);
    }

    public function test_render_statistics_block(): void
    {
        $block = $this->createBlock(BlockType::Statistics, [
            'stats' => [
                ['label' => 'Users', 'number' => '10K'],
                ['label' => 'Downloads', 'number' => '50K'],
            ],
            'columns' => 2,
        ]);

        $html = $this->renderer->render($block);

        $this->assertStringContainsString('Users', $html);
        $this->assertStringContainsString('10K', $html);
    }

    public function test_render_timeline_block(): void
    {
        $block = $this->createBlock(BlockType::Timeline, [
            'items' => [
                ['title' => 'Event 1', 'date' => '2024-01'],
                ['title' => 'Event 2', 'date' => '2024-02'],
            ],
        ]);

        $html = $this->renderer->render($block);

        $this->assertStringContainsString('Event 1', $html);
        $this->assertStringContainsString('Event 2', $html);
    }

    public function test_render_button_block(): void
    {
        $block = $this->createBlock(BlockType::Button, [
            'text' => 'Click Me',
            'link' => '/action',
            'style' => 'primary',
            'size' => 'lg',
        ]);

        $html = $this->renderer->render($block);

        $this->assertStringContainsString('Click Me', $html);
        $this->assertStringContainsString('/action', $html);
    }

    public function test_render_divider_block(): void
    {
        $block = $this->createBlock(BlockType::Divider, [
            'style' => 'solid',
            'color' => '#cccccc',
        ]);

        $html = $this->renderer->render($block);

        // Divider should render
        $this->assertIsString($html);
        $this->assertNotEmpty($html);
    }

    public function test_render_spacer_block(): void
    {
        $block = $this->createBlock(BlockType::Spacer, [
            'height' => '120',
        ]);

        $html = $this->renderer->render($block);

        $this->assertIsString($html);
        $this->assertNotEmpty($html);
    }

    public function test_render_video_block(): void
    {
        $block = $this->createBlock(BlockType::Video, [
            'video_url' => 'https://youtube.com/watch?v=test',
            'caption' => 'Test video',
        ]);

        $html = $this->renderer->render($block);

        $this->assertStringContainsString('youtube.com', $html);
        $this->assertStringContainsString('Test video', $html);
    }

    public function test_render_map_block(): void
    {
        $block = $this->createBlock(BlockType::Map, [
            'latitude' => '51.5074',
            'longitude' => '-0.1278',
            'title' => 'Our Office',
            'zoom' => '15',
        ]);

        $html = $this->renderer->render($block);

        $this->assertStringContainsString('51.5074', $html);
        $this->assertStringContainsString('-0.1278', $html);
        $this->assertStringContainsString('Our Office', $html);
    }

    public function test_render_contact_form_block(): void
    {
        $block = $this->createBlock(BlockType::ContactForm, [
            'title' => 'Contact Us',
            'description' => 'Get in touch',
            'fields' => [
                ['label' => 'Name', 'type' => 'text'],
                ['label' => 'Email', 'type' => 'email'],
            ],
            'button_text' => 'Send',
        ]);

        $html = $this->renderer->render($block);

        $this->assertStringContainsString('Contact Us', $html);
        $this->assertStringContainsString('Get in touch', $html);
        $this->assertStringContainsString('Send', $html);
    }

    public function test_get_component_name(): void
    {
        $this->assertEquals('hero', $this->renderer->getComponentName(BlockType::Hero));
        $this->assertEquals('rich-text', $this->renderer->getComponentName(BlockType::RichText));
        $this->assertEquals('gallery', $this->renderer->getComponentName(BlockType::Gallery));
        $this->assertEquals('features', $this->renderer->getComponentName(BlockType::Features));
        $this->assertEquals('pricing', $this->renderer->getComponentName(BlockType::Pricing));
        $this->assertEquals('featured-courses', $this->renderer->getComponentName(BlockType::FeaturedCourses));
        $this->assertEquals('featured-teachers', $this->renderer->getComponentName(BlockType::FeaturedTeachers));
        $this->assertEquals('newsletter', $this->renderer->getComponentName(BlockType::Newsletter));
        $this->assertEquals('contact-form', $this->renderer->getComponentName(BlockType::ContactForm));
    }

    private function createBlock(BlockType $type, array $content): ContentBlock
    {
        $page = Page::factory()->create();

        return ContentBlock::create([
            'blockable_type' => 'page',
            'blockable_id' => $page->id,
            'block_type' => $type,
            'content' => json_encode($content),
            'settings' => json_encode([]),
            'sort_order' => 1,
            'is_active' => true,
        ]);
    }
}
