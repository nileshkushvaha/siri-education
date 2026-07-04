<?php

namespace Tests\Unit\Services;

use App\Enums\BlockType;
use App\Services\BlockContentConverter;
use App\Services\BlockContentHydrator;
use Tests\TestCase;

class BlockContentHydratorTest extends TestCase
{
    /** @test */
    public function test_hydrates_hero_block_json_to_form_data(): void
    {
        $content = [
            'title' => 'Welcome to Our Site',
            'subtitle' => 'Discover amazing features',
            'image' => null,
            'button_text' => 'Get Started',
            'button_link' => '/features',
            'button_style' => 'primary',
        ];

        $result = BlockContentHydrator::hydrate(BlockType::Hero, $content);

        $this->assertEquals('Welcome to Our Site', $result['title']);
        $this->assertEquals('Discover amazing features', $result['subtitle']);
        $this->assertEquals('Get Started', $result['button_text']);
    }

    /** @test */
    public function test_hydrates_rich_text_block_json_to_form_data(): void
    {
        $content = [
            'text' => '<p>This is rich text content</p>',
        ];

        $result = BlockContentHydrator::hydrate(BlockType::RichText, $content);

        $this->assertEquals('<p>This is rich text content</p>', $result['text']);
    }

    /** @test */
    public function test_hydrates_image_block_json_to_form_data(): void
    {
        $content = [
            'image' => '/images/sample.jpg',
            'alt_text' => 'Sample image',
            'caption' => 'A beautiful sample',
        ];

        $result = BlockContentHydrator::hydrate(BlockType::Image, $content);

        $this->assertEquals('/images/sample.jpg', $result['image']);
        $this->assertEquals('Sample image', $result['alt_text']);
    }

    /** @test */
    public function test_hydrates_gallery_block_json_to_form_data(): void
    {
        $content = [
            'images' => [
                ['url' => '/img1.jpg'],
                ['url' => '/img2.jpg'],
            ],
            'columns' => 3,
            'gap' => 'md',
        ];

        $result = BlockContentHydrator::hydrate(BlockType::Gallery, $content);

        $this->assertCount(2, $result['images']);
        $this->assertEquals(3, $result['columns']);
    }

    /** @test */
    public function test_hydrates_video_block_json_to_form_data(): void
    {
        $content = [
            'video_url' => 'https://youtube.com/watch?v=abc123',
            'caption' => 'My Video',
            'thumbnail' => '/thumb.jpg',
        ];

        $result = BlockContentHydrator::hydrate(BlockType::Video, $content);

        $this->assertEquals('https://youtube.com/watch?v=abc123', $result['video_url']);
        $this->assertEquals('My Video', $result['caption']);
    }

    /** @test */
    public function test_hydrates_cta_block_json_to_form_data(): void
    {
        $content = [
            'title' => 'Ready to Start?',
            'description' => 'Join thousands of users',
            'button_text' => 'Sign Up Now',
            'button_link' => '/signup',
            'button_style' => 'secondary',
            'background_color' => '#ffffff',
        ];

        $result = BlockContentHydrator::hydrate(BlockType::CTA, $content);

        $this->assertEquals('Ready to Start?', $result['title']);
        $this->assertEquals('Join thousands of users', $result['description']);
        $this->assertEquals('Sign Up Now', $result['button_text']);
    }

    /** @test */
    public function test_hydrates_faq_block_json_to_form_data(): void
    {
        $content = [
            'items' => [
                ['question' => 'Q1?', 'answer' => 'Answer 1'],
                ['question' => 'Q2?', 'answer' => 'Answer 2'],
            ],
        ];

        $result = BlockContentHydrator::hydrate(BlockType::FAQ, $content);

        $this->assertCount(2, $result['items']);
        $this->assertEquals('Q1?', $result['items'][0]['question']);
    }

    /** @test */
    public function test_hydrates_accordion_block_json_to_form_data(): void
    {
        $content = [
            'items' => [
                ['title' => 'Section 1', 'content' => 'Content 1'],
            ],
            'single_open' => true,
        ];

        $result = BlockContentHydrator::hydrate(BlockType::Accordion, $content);

        $this->assertCount(1, $result['items']);
        $this->assertTrue($result['single_open']);
    }

    /** @test */
    public function test_hydrates_tabs_block_json_to_form_data(): void
    {
        $content = [
            'items' => [
                ['tab_title' => 'Tab 1', 'content' => 'Content 1'],
            ],
        ];

        $result = BlockContentHydrator::hydrate(BlockType::Tabs, $content);

        $this->assertCount(1, $result['items']);
    }

    /** @test */
    public function test_hydrates_team_block_json_to_form_data(): void
    {
        $content = [
            'title' => 'Our Team',
            'description' => 'Meet the team',
            'members' => [
                ['name' => 'John Doe', 'role' => 'CEO'],
            ],
            'columns' => 3,
        ];

        $result = BlockContentHydrator::hydrate(BlockType::Team, $content);

        $this->assertEquals('Our Team', $result['title']);
        $this->assertCount(1, $result['members']);
        $this->assertEquals(3, $result['columns']);
    }

    /** @test */
    public function test_hydrates_features_block_json_to_form_data(): void
    {
        $content = [
            'eyebrow' => 'Capabilities',
            'title' => 'Platform Features',
            'description' => 'Reusable page sections',
            'features' => [
                ['title' => 'CMS Blocks', 'description' => 'Rendered dynamically'],
            ],
            'columns' => 3,
        ];

        $result = BlockContentHydrator::hydrate(BlockType::Features, $content);

        $this->assertEquals('Capabilities', $result['eyebrow']);
        $this->assertEquals('Platform Features', $result['title']);
        $this->assertCount(1, $result['features']);
        $this->assertEquals(3, $result['columns']);
    }

    /** @test */
    public function test_hydrates_testimonials_block_json_to_form_data(): void
    {
        $content = [
            'testimonials' => [
                ['text' => 'Great product!', 'author' => 'John'],
            ],
            'columns' => 3,
        ];

        $result = BlockContentHydrator::hydrate(BlockType::Testimonials, $content);

        $this->assertCount(1, $result['testimonials']);
        $this->assertEquals(3, $result['columns']);
    }

    /** @test */
    public function test_hydrates_pricing_block_json_to_form_data(): void
    {
        $content = [
            'title' => 'Pricing',
            'plans' => [
                ['name' => 'Pro', 'features' => ['Feature one']],
            ],
            'columns' => 3,
        ];

        $result = BlockContentHydrator::hydrate(BlockType::Pricing, $content);

        $this->assertEquals('Pricing', $result['title']);
        $this->assertCount(1, $result['plans']);
        $this->assertEquals(3, $result['columns']);
    }

    /** @test */
    public function test_hydrates_featured_courses_block_json_to_form_data(): void
    {
        $content = [
            'title' => 'Featured Courses',
            'courses' => [
                ['title' => 'Algebra', 'category' => 'Math'],
            ],
            'columns' => 3,
        ];

        $result = BlockContentHydrator::hydrate(BlockType::FeaturedCourses, $content);

        $this->assertEquals('Featured Courses', $result['title']);
        $this->assertCount(1, $result['courses']);
        $this->assertEquals(3, $result['columns']);
    }

    /** @test */
    public function test_hydrates_featured_teachers_block_json_to_form_data(): void
    {
        $result = BlockContentHydrator::hydrate(BlockType::FeaturedTeachers, [
            'title' => 'Featured Teachers',
            'limit' => 6,
            'columns' => 3,
        ]);

        $this->assertEquals('Featured Teachers', $result['title']);
        $this->assertEquals(6, $result['limit']);
        $this->assertEquals(3, $result['columns']);
    }

    /** @test */
    public function test_hydrates_newsletter_block_json_to_form_data(): void
    {
        $result = BlockContentHydrator::hydrate(BlockType::Newsletter, [
            'title' => 'Newsletter',
            'email_label' => 'Email',
            'button_text' => 'Subscribe',
        ]);

        $this->assertEquals('Newsletter', $result['title']);
        $this->assertEquals('Email', $result['email_label']);
        $this->assertEquals('Subscribe', $result['button_text']);
    }

    /** @test */
    public function test_hydrates_statistics_block_json_to_form_data(): void
    {
        $content = [
            'stats' => [
                ['number' => '10K', 'label' => 'Users'],
            ],
            'columns' => 4,
        ];

        $result = BlockContentHydrator::hydrate(BlockType::Statistics, $content);

        $this->assertCount(1, $result['stats']);
        $this->assertEquals(4, $result['columns']);
    }

    /** @test */
    public function test_hydrates_timeline_block_json_to_form_data(): void
    {
        $content = [
            'items' => [
                ['title' => 'Event 1'],
            ],
        ];

        $result = BlockContentHydrator::hydrate(BlockType::Timeline, $content);

        $this->assertCount(1, $result['items']);
    }

    /** @test */
    public function test_hydrates_button_block_json_to_form_data(): void
    {
        $content = [
            'text' => 'Click Me',
            'link' => '/action',
            'style' => 'primary',
            'size' => 'lg',
            'alignment' => 'center',
        ];

        $result = BlockContentHydrator::hydrate(BlockType::Button, $content);

        $this->assertEquals('Click Me', $result['text']);
        $this->assertEquals('/action', $result['link']);
        $this->assertEquals('primary', $result['style']);
    }

    /** @test */
    public function test_hydrates_divider_block_json_to_form_data(): void
    {
        $content = [
            'style' => 'solid',
            'color' => '#cccccc',
            'width' => '100',
        ];

        $result = BlockContentHydrator::hydrate(BlockType::Divider, $content);

        $this->assertEquals('solid', $result['style']);
        $this->assertEquals('#cccccc', $result['color']);
    }

    /** @test */
    public function test_hydrates_spacer_block_json_to_form_data(): void
    {
        $content = [
            'height' => '120',
        ];

        $result = BlockContentHydrator::hydrate(BlockType::Spacer, $content);

        $this->assertEquals('120', $result['height']);
    }

    /** @test */
    public function test_hydrates_map_block_json_to_form_data(): void
    {
        $content = [
            'latitude' => '51.5074',
            'longitude' => '-0.1278',
            'address' => 'London, UK',
            'title' => 'Our Office',
            'zoom' => '12',
        ];

        $result = BlockContentHydrator::hydrate(BlockType::Map, $content);

        $this->assertEquals('51.5074', $result['latitude']);
        $this->assertEquals('-0.1278', $result['longitude']);
        $this->assertEquals('London, UK', $result['address']);
    }

    /** @test */
    public function test_hydrates_contact_form_block_json_to_form_data(): void
    {
        $content = [
            'title' => 'Contact Us',
            'description' => 'We would love to hear from you',
            'fields' => [
                ['label' => 'Name', 'type' => 'text'],
            ],
            'button_text' => 'Send',
            'success_message' => 'Thanks!',
        ];

        $result = BlockContentHydrator::hydrate(BlockType::ContactForm, $content);

        $this->assertEquals('Contact Us', $result['title']);
        $this->assertCount(1, $result['fields']);
        $this->assertEquals('Send', $result['button_text']);
    }

    /** @test */
    public function test_returns_default_values_for_missing_fields(): void
    {
        $content = [];

        $result = BlockContentHydrator::hydrate(BlockType::Hero, $content);

        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('subtitle', $result);
        $this->assertEquals('', $result['title']);
        $this->assertEquals('primary', $result['button_style']);
    }

    /** @test */
    public function test_round_trips_converter_and_hydrator(): void
    {
        $originalFormData = [
            'title' => 'Test Hero',
            'subtitle' => 'Subtitle',
            'image' => null,
            'button_text' => 'Click',
            'button_link' => '/test',
            'button_style' => 'secondary',
        ];

        // Convert to JSON
        $jsonContent = BlockContentConverter::convert(BlockType::Hero, $originalFormData);

        // Hydrate back to form data
        $hydratedFormData = BlockContentHydrator::hydrate(BlockType::Hero, $jsonContent);

        // Should be identical
        $this->assertEquals($originalFormData, $hydratedFormData);
    }
}
