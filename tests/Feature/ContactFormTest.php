<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Content\Models\ContentBlock;
use App\Enums\BlockType;
use App\Models\Page;
use App\Settings\BookingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContactFormTest extends TestCase
{
    use RefreshDatabase;

    private function makeBlock(): ContentBlock
    {
        $page = Page::factory()->create();

        return ContentBlock::create([
            'blockable_type' => 'page',
            'blockable_id' => $page->id,
            'block_type' => BlockType::ContactForm,
            'content' => json_encode([
                'fields' => [
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                    ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                ],
            ]),
            'settings' => json_encode([]),
            'sort_order' => 1,
            'is_active' => true,
        ]);
    }

    public function test_submits_successfully_when_captcha_is_disabled(): void
    {
        $block = $this->makeBlock();

        $this->post(route('contact.submit'), [
            'block_id' => $block->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ])->assertRedirect();

        $this->assertDatabaseHas('activity_log', ['log_name' => 'contact']);
    }

    public function test_honeypot_still_rejects_bots(): void
    {
        $block = $this->makeBlock();

        $this->post(route('contact.submit'), [
            'block_id' => $block->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'website' => 'http://spam.example',
        ])->assertSessionHasErrors('website');
    }

    public function test_captcha_blocks_submission_when_enabled_and_token_missing(): void
    {
        $settings = app(BookingSettings::class);
        $settings->captcha_enabled = true;
        $settings->turnstile_site_key = 'site-key';
        $settings->turnstile_secret_key = 'secret-key';
        $settings->save();

        $block = $this->makeBlock();

        $this->post(route('contact.submit'), [
            'block_id' => $block->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ])->assertSessionHasErrors('cf-turnstile-response');
    }

    public function test_captcha_allows_submission_when_enabled_and_token_verifies(): void
    {
        $settings = app(BookingSettings::class);
        $settings->captcha_enabled = true;
        $settings->turnstile_site_key = 'site-key';
        $settings->turnstile_secret_key = 'secret-key';
        $settings->save();

        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true]),
        ]);

        $block = $this->makeBlock();

        $this->post(route('contact.submit'), [
            'block_id' => $block->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'cf-turnstile-response' => 'valid-token',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }
}
