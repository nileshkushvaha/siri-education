<?php

namespace Tests\Unit\Services;

use App\Enums\BlockType;
use App\Services\BlockContentConverter;
use Tests\TestCase;

class BlockContentConverterTest extends TestCase
{
    /** @test */
    public function test_converts_hero_block_form_data_to_json(): void
    {
        $formData = [
            'title' => 'Welcome to Our Site',
            'subtitle' => 'Discover amazing features',
            'image' => null,
            'button_text' => 'Get Started',
            'button_link' => '/features',
            'button_style' => 'primary',
        ];

        $result = BlockContentConverter::convert(BlockType::Hero, $formData);

        $this->assertEquals('Welcome to Our Site', $result['title']);
        $this->assertEquals('Discover amazing features', $result['subtitle']);
        $this->assertEquals('Get Started', $result['button_text']);
        $this->assertEquals('/features', $result['button_link']);
        $this->assertEquals('primary', $result['button_style']);
    }

    /** @test */
    public function test_converts_rich_text_block_form_data_to_json(): void
    {
        $formData = [
            'text' => '<p>This is rich text content</p>',
        ];

        $result = BlockContentConverter::convert(BlockType::RichText, $formData);

        $this->assertEquals('<p>This is rich text content</p>', $result['text']);
    }

    /** @test */
    public function test_converts_image_block_form_data_to_json(): void
    {
        $formData = [
            'image' => '/images/sample.jpg',
            'alt_text' => 'Sample image',
            'caption' => 'A beautiful sample',
        ];

        $result = BlockContentConverter::convert(BlockType::Image, $formData);

        $this->assertEquals('/images/sample.jpg', $result['image']);
        $this->assertEquals('Sample image', $result['alt_text']);
        $this->assertEquals('A beautiful sample', $result['caption']);
    }

    /** @test */
    public function test_converts_gallery_block_form_data_to_json(): void
    {
        $formData = [
            'images' => [
                ['url' => '/img1.jpg', 'caption' => 'Image 1'],
                ['url' => '/img2.jpg', 'caption' => 'Image 2'],
            ],
            'columns' => 3,
            'gap' => 'md',
        ];

        $result = BlockContentConverter::convert(BlockType::Gallery, $formData);

        $this->assertCount(2, $result['images']);
        $this->assertEquals(3, $result['columns']);
        $this->assertEquals('md', $result['gap']);
    }

    /** @test */
    public function test_converts_video_block_form_data_to_json(): void
    {
        $formData = [
            'video_url' => 'https://youtube.com/watch?v=abc123',
            'caption' => 'My Video',
            'thumbnail' => '/thumb.jpg',
        ];

        $result = BlockContentConverter::convert(BlockType::Video, $formData);

        $this->assertEquals('https://youtube.com/watch?v=abc123', $result['video_url']);
        $this->assertEquals('My Video', $result['caption']);
        $this->assertEquals('/thumb.jpg', $result['thumbnail']);
    }

    /** @test */
    public function test_converts_cta_block_form_data_to_json(): void
    {
        $formData = [
            'title' => 'Ready to Start?',
            'description' => 'Join thousands of users',
            'button_text' => 'Sign Up Now',
            'button_link' => '/signup',
            'button_style' => 'secondary',
            'background_color' => '#ffffff',
            'text_color' => '#000000',
        ];

        $result = BlockContentConverter::convert(BlockType::CTA, $formData);

        $this->assertEquals('Ready to Start?', $result['title']);
        $this->assertEquals('Join thousands of users', $result['description']);
        $this->assertEquals('Sign Up Now', $result['button_text']);
        $this->assertEquals('/signup', $result['button_link']);
        $this->assertEquals('secondary', $result['button_style']);
    }

    /** @test */
    public function test_converts_faq_block_form_data_to_json(): void
    {
        $formData = [
            'items' => [
                ['question' => 'Q1?', 'answer' => 'Answer 1'],
                ['question' => 'Q2?', 'answer' => 'Answer 2'],
            ],
        ];

        $result = BlockContentConverter::convert(BlockType::FAQ, $formData);

        $this->assertCount(2, $result['items']);
        $this->assertEquals('Q1?', $result['items'][0]['question']);
        $this->assertEquals('Answer 1', $result['items'][0]['answer']);
    }

    /** @test */
    public function test_filters_empty_faq_items(): void
    {
        $formData = [
            'items' => [
                ['question' => 'Q1?', 'answer' => 'Answer 1'],
                ['question' => '', 'answer' => ''],
                ['question' => 'Q2?', 'answer' => 'Answer 2'],
            ],
        ];

        $result = BlockContentConverter::convert(BlockType::FAQ, $formData);

        $this->assertCount(2, $result['items']);
    }

    /** @test */
    public function test_converts_accordion_block_form_data_to_json(): void
    {
        $formData = [
            'items' => [
                ['title' => 'Section 1', 'content' => 'Content 1'],
                ['title' => 'Section 2', 'content' => 'Content 2'],
            ],
            'single_open' => true,
        ];

        $result = BlockContentConverter::convert(BlockType::Accordion, $formData);

        $this->assertCount(2, $result['items']);
        $this->assertTrue($result['single_open']);
    }

    /** @test */
    public function test_converts_tabs_block_form_data_to_json(): void
    {
        $formData = [
            'items' => [
                ['tab_title' => 'Tab 1', 'content' => 'Content 1'],
                ['tab_title' => 'Tab 2', 'content' => 'Content 2'],
            ],
        ];

        $result = BlockContentConverter::convert(BlockType::Tabs, $formData);

        $this->assertCount(2, $result['items']);
    }

    /** @test */
    public function test_converts_team_block_form_data_to_json(): void
    {
        $formData = [
            'title' => 'Our Team',
            'description' => 'Meet the team',
            'members' => [
                ['name' => 'John Doe', 'role' => 'CEO'],
                ['name' => 'Jane Smith', 'role' => 'CTO'],
            ],
            'columns' => 3,
        ];

        $result = BlockContentConverter::convert(BlockType::Team, $formData);

        $this->assertEquals('Our Team', $result['title']);
        $this->assertCount(2, $result['members']);
        $this->assertEquals(3, $result['columns']);
    }

    /** @test */
    public function test_converts_features_block_form_data_to_json(): void
    {
        $formData = [
            'eyebrow' => 'Capabilities',
            'title' => 'Platform Features',
            'description' => 'Reusable page sections',
            'features' => [
                ['title' => 'CMS Blocks', 'description' => 'Rendered dynamically', 'link' => '/features', 'link_label' => 'Read more'],
                ['title' => '', 'description' => 'Ignored'],
            ],
            'columns' => 3,
        ];

        $result = BlockContentConverter::convert(BlockType::Features, $formData);

        $this->assertEquals('Capabilities', $result['eyebrow']);
        $this->assertEquals('Platform Features', $result['title']);
        $this->assertCount(1, $result['features']);
        $this->assertEquals('CMS Blocks', $result['features'][0]['title']);
    }

    /** @test */
    public function test_converts_testimonials_block_form_data_to_json(): void
    {
        $formData = [
            'testimonials' => [
                ['text' => 'Great product!', 'author' => 'John'],
                ['text' => 'Amazing service!', 'author' => 'Jane'],
            ],
            'columns' => 3,
        ];

        $result = BlockContentConverter::convert(BlockType::Testimonials, $formData);

        $this->assertCount(2, $result['testimonials']);
        $this->assertEquals(3, $result['columns']);
    }

    /** @test */
    public function test_converts_pricing_block_form_data_to_json(): void
    {
        $formData = [
            'title' => 'Pricing',
            'plans' => [
                [
                    'name' => 'Pro',
                    'price' => '$49',
                    'features' => "Feature one\nFeature two",
                    'highlighted' => true,
                ],
                ['name' => '', 'price' => '$0'],
            ],
            'columns' => 3,
        ];

        $result = BlockContentConverter::convert(BlockType::Pricing, $formData);

        $this->assertEquals('Pricing', $result['title']);
        $this->assertCount(1, $result['plans']);
        $this->assertEquals(['Feature one', 'Feature two'], $result['plans'][0]['features']);
        $this->assertTrue($result['plans'][0]['highlighted']);
    }

    /** @test */
    public function test_converts_featured_teachers_block_form_data_to_json(): void
    {
        $result = BlockContentConverter::convert(BlockType::FeaturedTeachers, [
            'title' => 'Featured Teachers',
            'limit' => 99,
            'columns' => 4,
        ]);

        $this->assertEquals('Featured Teachers', $result['title']);
        $this->assertEquals(12, $result['limit']);
        $this->assertEquals(4, $result['columns']);
    }

    /** @test */
    public function test_converts_newsletter_block_form_data_to_json(): void
    {
        $result = BlockContentConverter::convert(BlockType::Newsletter, [
            'title' => 'Newsletter',
            'email_label' => 'Email',
            'button_text' => 'Subscribe',
        ]);

        $this->assertEquals('Newsletter', $result['title']);
        $this->assertEquals('Email', $result['email_label']);
        $this->assertEquals('Subscribe', $result['button_text']);
    }

    /** @test */
    public function test_converts_statistics_block_form_data_to_json(): void
    {
        $formData = [
            'stats' => [
                ['number' => '10K', 'label' => 'Users'],
                ['number' => '50K', 'label' => 'Downloads'],
            ],
            'columns' => 4,
        ];

        $result = BlockContentConverter::convert(BlockType::Statistics, $formData);

        $this->assertCount(2, $result['stats']);
        $this->assertEquals(4, $result['columns']);
    }

    /** @test */
    public function test_converts_timeline_block_form_data_to_json(): void
    {
        $formData = [
            'items' => [
                ['title' => 'Event 1', 'date' => '2024-01'],
                ['title' => 'Event 2', 'date' => '2024-02'],
            ],
        ];

        $result = BlockContentConverter::convert(BlockType::Timeline, $formData);

        $this->assertCount(2, $result['items']);
    }

    /** @test */
    public function test_converts_button_block_form_data_to_json(): void
    {
        $formData = [
            'text' => 'Click Me',
            'link' => '/action',
            'style' => 'primary',
            'size' => 'lg',
            'alignment' => 'center',
        ];

        $result = BlockContentConverter::convert(BlockType::Button, $formData);

        $this->assertEquals('Click Me', $result['text']);
        $this->assertEquals('/action', $result['link']);
        $this->assertEquals('primary', $result['style']);
        $this->assertEquals('lg', $result['size']);
        $this->assertEquals('center', $result['alignment']);
    }

    /** @test */
    public function test_converts_divider_block_form_data_to_json(): void
    {
        $formData = [
            'style' => 'solid',
            'color' => '#cccccc',
            'width' => 100,
        ];

        $result = BlockContentConverter::convert(BlockType::Divider, $formData);

        $this->assertEquals('solid', $result['style']);
        $this->assertEquals('#cccccc', $result['color']);
        $this->assertEquals(100, $result['width']);
    }

    /** @test */
    public function test_converts_spacer_block_form_data_to_json(): void
    {
        $formData = [
            'height' => 120,
        ];

        $result = BlockContentConverter::convert(BlockType::Spacer, $formData);

        $this->assertEquals(120, $result['height']);
    }

    /** @test */
    public function test_converts_map_block_form_data_to_json(): void
    {
        $formData = [
            'latitude' => '51.5074',
            'longitude' => '-0.1278',
            'address' => 'London, UK',
            'title' => 'Our Office',
            'zoom' => 12,
        ];

        $result = BlockContentConverter::convert(BlockType::Map, $formData);

        $this->assertEquals('51.5074', $result['latitude']);
        $this->assertEquals('-0.1278', $result['longitude']);
        $this->assertEquals('London, UK', $result['address']);
        $this->assertEquals(12, $result['zoom']);
    }

    /** @test */
    public function test_converts_contact_form_block_form_data_to_json(): void
    {
        $formData = [
            'title' => 'Contact Us',
            'description' => 'We would love to hear from you',
            'fields' => [
                ['label' => 'Name', 'type' => 'text'],
                ['label' => 'Email', 'type' => 'email'],
            ],
            'button_text' => 'Send',
            'success_message' => 'Thanks for contacting us!',
        ];

        $result = BlockContentConverter::convert(BlockType::ContactForm, $formData);

        $this->assertEquals('Contact Us', $result['title']);
        $this->assertCount(2, $result['fields']);
        $this->assertEquals('Send', $result['button_text']);
    }

    /** @test */
    public function test_provides_default_values_for_missing_fields(): void
    {
        $formData = [];

        $result = BlockContentConverter::convert(BlockType::Hero, $formData);

        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('subtitle', $result);
        $this->assertArrayHasKey('button_text', $result);
        $this->assertEquals('primary', $result['button_style']);
    }
}
