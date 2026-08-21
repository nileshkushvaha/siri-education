<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Models\Page;
use App\Models\Redirect;
use App\Models\User;
use Database\Seeders\PolicyPagesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The policy pages a payment gateway checks during merchant review.
 */
class PolicyPagesTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private const PAGES = [
        'terms-and-conditions' => 'Terms and Conditions',
        'privacy-policy' => 'Privacy Policy',
        'cancellation-and-refund-policy' => 'Cancellation and Refund Policy',
        'shipping-and-exchange-policy' => 'Shipping and Exchange Policy',
    ];

    public function test_all_four_policy_pages_resolve(): void
    {
        $this->seed(PolicyPagesSeeder::class);

        foreach (self::PAGES as $slug => $title) {
            $this->get('/'.$slug)
                ->assertOk()
                ->assertSee($title, false);
        }
    }

    public function test_each_policy_page_has_one_h1_and_its_own_canonical(): void
    {
        $this->seed(PolicyPagesSeeder::class);

        foreach (array_keys(self::PAGES) as $slug) {
            $html = $this->get('/'.$slug)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, '<h1'), "{$slug} must have exactly one H1.");
            $this->assertStringContainsString('<link rel="canonical" href="'.url('/'.$slug).'"', $html);

            $page = Page::query()->where('slug', $slug)->firstOrFail();
            $this->assertLessThanOrEqual(70, mb_strlen((string) $page->meta_title));
            $this->assertLessThanOrEqual(160, mb_strlen((string) $page->meta_description));
        }
    }

    public function test_the_policy_pages_cross_link_the_way_a_gateway_review_expects(): void
    {
        $this->seed(PolicyPagesSeeder::class);

        $terms = $this->get('/terms-and-conditions')->assertOk()->getContent();
        $this->assertStringContainsString('/cancellation-and-refund-policy', $terms);
        $this->assertStringContainsString('/privacy-policy', $terms);

        $shipping = $this->get('/shipping-and-exchange-policy')->assertOk()->getContent();
        $this->assertStringContainsString('/cancellation-and-refund-policy', $shipping);

        foreach (array_keys(self::PAGES) as $slug) {
            $this->assertStringContainsString('/contact-us', $this->get('/'.$slug)->getContent());
        }
    }

    public function test_shipping_page_states_that_no_physical_goods_are_shipped(): void
    {
        $this->seed(PolicyPagesSeeder::class);

        $this->get('/shipping-and-exchange-policy')
            ->assertOk()
            ->assertSee('do not sell, ship or deliver physical goods', false);
    }

    public function test_refund_page_avoids_promising_a_processing_timeline(): void
    {
        $this->seed(PolicyPagesSeeder::class);

        $html = $this->get('/cancellation-and-refund-policy')->assertOk()->getContent();

        // A day count here would be a promise the platform cannot keep:
        // once a refund is issued the timing belongs to the bank.
        $this->assertDoesNotMatchRegularExpression('/\b\d+\s*[-–to]+\s*\d+\s+(business|working)\s+days\b/i', $html);
    }

    public function test_refund_page_does_not_hardcode_the_configurable_cancellation_window(): void
    {
        $this->seed(PolicyPagesSeeder::class);

        $html = $this->get('/cancellation-and-refund-policy')->assertOk()->getContent();

        // BookingSettings::cancellation_window_hours is admin-editable, so
        // a number written into the copy would silently become a lie.
        $this->assertDoesNotMatchRegularExpression('/\b\d+\s*hours?\s+(before|prior)/i', $html);
        $this->assertStringContainsString('cancellation window', $html);
    }

    public function test_terms_of_service_redirects_permanently_to_terms_and_conditions(): void
    {
        Role::findOrCreate('super_admin', 'web');
        User::factory()->create()->assignRole('super_admin');

        $this->seed(PolicyPagesSeeder::class);

        $this->get('/terms-of-service')->assertRedirect(url('/terms-and-conditions'));
        $this->assertDatabaseHas('redirects', [
            'source_path' => '/terms-of-service',
            'target_path' => '/terms-and-conditions',
            'type' => '301',
        ]);
    }

    public function test_the_redirect_is_skipped_rather_than_left_unattributed(): void
    {
        // redirects.created_by is NOT NULL by design. With nobody to
        // attribute the row to, the pages must still seed.
        $this->seed(PolicyPagesSeeder::class);

        $this->assertSame(0, Redirect::query()->count());
        $this->get('/terms-and-conditions')->assertOk();
    }

    public function test_seeder_is_idempotent_and_does_not_overwrite_edits(): void
    {
        $this->seed(PolicyPagesSeeder::class);

        $page = Page::query()->where('slug', 'terms-and-conditions')->firstOrFail();
        $page->update(['content' => '<div class="policy-document"><p>Counsel rewrote this.</p></div>']);

        $count = Page::query()->count();

        $this->seed(PolicyPagesSeeder::class);
        $this->seed(PolicyPagesSeeder::class);

        $this->assertSame($count, Page::query()->count());
        $this->assertStringContainsString('Counsel rewrote this.', (string) $page->fresh()->content);
    }

    public function test_legal_content_is_editable_because_it_is_not_structure_locked(): void
    {
        $this->seed(PolicyPagesSeeder::class);

        // StructuredPageContentService reverts an update that drops the
        // data-cms-structured-page marker. Legal pages must never carry
        // it, or counsel could not replace the document wholesale.
        $page = Page::query()->where('slug', 'privacy-policy')->firstOrFail();
        $this->assertStringNotContainsString('data-cms-structured-page', (string) $page->content);

        $page->update(['content' => '<p>Entirely new privacy text.</p>']);

        $this->assertSame('<p>Entirely new privacy text.</p>', $page->fresh()->content);
    }

    public function test_placeholders_are_present_so_they_cannot_be_published_unnoticed(): void
    {
        $this->seed(PolicyPagesSeeder::class);

        $this->assertStringContainsString(
            '[REPLACE:',
            (string) Page::query()->where('slug', 'terms-and-conditions')->value('content'),
        );
    }

    public function test_the_operator_is_described_as_an_individual_not_a_registered_company(): void
    {
        $this->seed(PolicyPagesSeeder::class);

        // The platform is run by an unregistered sole proprietor. A gateway
        // verifies the merchant name against the ID and bank account behind
        // the payout, so naming a company that does not exist both fails
        // review and misstates who the contract is with. When one is
        // incorporated, this expectation is the reminder to update the copy.
        foreach (['terms-and-conditions', 'privacy-policy'] as $slug) {
            $content = (string) Page::query()->where('slug', $slug)->value('content');

            $this->assertStringContainsString('[REPLACE: your full legal name', $content);
            $this->assertStringNotContainsString('registered entity name', $content);
            $this->assertDoesNotMatchRegularExpression('/\bCIN\b|company registration number/i', $content);
        }

        $this->assertStringContainsString(
            'sole proprietor',
            (string) Page::query()->where('slug', 'terms-and-conditions')->value('content'),
        );
    }

    public function test_footer_links_to_every_policy_page(): void
    {
        $this->seed(PolicyPagesSeeder::class);

        $html = $this->get('/terms-and-conditions')->assertOk()->getContent();

        foreach (array_keys(self::PAGES) as $slug) {
            $this->assertStringContainsString('/'.$slug, $html);
        }
    }
}
