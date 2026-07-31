<?php

namespace Tests\Feature\Cms;

use App\Content\Models\ContentBlock;
use App\Enums\BlockType;
use Database\Seeders\ContactUsPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactUsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_page_uses_real_support_paths_and_secure_cms_form(): void
    {
        $this->seed(ContactUsPageSeeder::class);

        $this->get('/contact-us')
            ->assertOk()
            ->assertSee('Clear help starts with the right context')
            ->assertSee('Include what helps. Protect what matters.')
            ->assertSee('Send message securely')
            ->assertDontSee('/support', false)
            ->assertDontSee('/callback', false)
            ->assertDontSee('/feedback', false)
            ->assertDontSee('/inquiry', false)
            ->assertDontSee('I have create about us page');

        $this->assertDatabaseHas('content_blocks', [
            'name' => 'Contact Us — Primary Form',
            'block_type' => BlockType::ContactForm->value,
            'is_active' => true,
        ]);

        $this->assertCount(1, ContentBlock::query()->where('name', 'Contact Us — Primary Form')->get());
    }
}
