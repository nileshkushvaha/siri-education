<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Services\CmsCacheService;
use App\Settings\SeoSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Covers the SEO settings that had a UI but no effect.
 *
 * The analytics/verification IDs previously reached only three layouts, and
 * robots.txt ignored the Robots directive entirely. Both were the kind of gap
 * an administrator cannot detect from the admin panel: the field saves, so it
 * looks applied.
 */
class SeoSettingsWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/settings']);
        Cache::flush();
    }

    private function seo(array $overrides): SeoSettings
    {
        $settings = app(SeoSettings::class);

        foreach ($overrides as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();

        return $settings;
    }

    // ── robots.txt honours the Robots directive ──────────────────────

    public function test_robots_txt_allows_crawling_on_the_default_directive(): void
    {
        $this->seo(['robots' => 'index,follow']);

        $response = $this->get('/robots.txt')->assertOk();
        $body = $response->getContent();

        $this->assertStringContainsString('Allow: /', $body);
        $this->assertStringNotContainsString('Disallow: /'."\n", $body);
        $this->assertStringContainsString('Disallow: /admin', $body);
    }

    public function test_robots_txt_disallows_everything_when_the_site_is_set_to_noindex(): void
    {
        $this->seo(['robots' => 'noindex,nofollow']);

        $body = $this->get('/robots.txt')->assertOk()->getContent();

        // The whole point: a site set to noindex must not keep inviting crawlers.
        $this->assertStringContainsString('Disallow: /', $body);
        $this->assertStringNotContainsString('Allow: /', $body);
    }

    public function test_nofollow_alone_does_not_disallow_the_site(): void
    {
        // 'index,nofollow' still wants the site indexed — only link-following
        // is restricted, which robots.txt cannot express.
        $this->seo(['robots' => 'index,nofollow']);

        $body = $this->get('/robots.txt')->assertOk()->getContent();

        $this->assertStringContainsString('Allow: /', $body);
    }

    public function test_the_sitemap_is_always_advertised(): void
    {
        $this->seo(['robots' => 'noindex,nofollow']);

        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap: ', escape: false);
    }

    public function test_changing_the_directive_is_not_masked_by_the_robots_cache(): void
    {
        $this->seo(['robots' => 'index,follow']);
        $this->get('/robots.txt')->assertOk()->assertSee('Allow: /', escape: false);

        // robots.txt is cached for an hour under a discovery-versioned key, so
        // the settings page bumps that version on save. Without it the switch
        // would appear to do nothing.
        $this->seo(['robots' => 'noindex,nofollow']);
        app(CmsCacheService::class)->bumpDiscoveryVersion();

        $body = $this->get('/robots.txt')->assertOk()->getContent();

        $this->assertStringContainsString('Disallow: /', $body);
        $this->assertStringNotContainsString('Allow: /', $body);
    }

    // ── Tracking tags reach every layout ─────────────────────────────

    public function test_analytics_and_verification_render_on_a_public_page(): void
    {
        $this->seo([
            'google_analytics_id' => 'G-TESTID0001',
            'google_tag_manager_id' => 'GTM-TEST001',
            'facebook_pixel_id' => '000000000000001',
            'google_search_console_verification' => 'verify-token-001',
        ]);

        $html = $this->get(route('auth.login'))->assertOk()->getContent();

        $this->assertStringContainsString('verify-token-001', $html);
        $this->assertStringContainsString('G-TESTID0001', $html);
        $this->assertStringContainsString('GTM-TEST001', $html);
        $this->assertStringContainsString('000000000000001', $html);
    }

    public function test_nothing_is_emitted_when_no_ids_are_configured(): void
    {
        $this->seo([
            'google_analytics_id' => null,
            'google_tag_manager_id' => null,
            'facebook_pixel_id' => null,
            'google_search_console_verification' => null,
        ]);

        $html = $this->get(route('auth.login'))->assertOk()->getContent();

        // An unset ID must not leave an empty tag behind.
        $this->assertStringNotContainsString('googletagmanager.com', $html);
        $this->assertStringNotContainsString('connect.facebook.net', $html);
        $this->assertStringNotContainsString('google-site-verification', $html);
    }

    public function test_error_pages_do_not_break_when_tracking_is_configured(): void
    {
        $this->seo(['google_analytics_id' => 'G-TESTID0002']);

        // layouts/error includes the same partial, and renders precisely when
        // the app is already failing — it must never add a second failure.
        $this->get('/a-route-that-does-not-exist')->assertNotFound();
    }
}
