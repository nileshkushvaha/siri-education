<?php

declare(strict_types=1);

namespace Tests\Feature\Redirects;

use App\Content\Redirects\Enums\RedirectType;
use App\Content\Redirects\Services\RedirectService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GAP-036 (SRS §22.25/26): redirects are applied on the public request
 * path only, ahead of the plain 404 page, and never inside admin/API/
 * authenticated territory.
 */
final class RedirectPublicRoutingTest extends TestCase
{
    use RefreshDatabase;

    private RedirectService $redirects;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redirects = app(RedirectService::class);
        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
    }

    public function test_a_301_redirect_is_applied_on_an_unmatched_public_path(): void
    {
        $this->redirects->create($this->admin, ['source_path' => '/old-page', 'target_path' => '/about-us', 'type' => RedirectType::Permanent]);

        $this->get('/old-page')
            ->assertRedirect('/about-us')
            ->assertStatus(301);
    }

    public function test_a_302_redirect_is_applied_on_an_unmatched_public_path(): void
    {
        $this->redirects->create($this->admin, ['source_path' => '/temp-page', 'target_path' => '/about-us', 'type' => RedirectType::Temporary]);

        $this->get('/temp-page')
            ->assertRedirect('/about-us')
            ->assertStatus(302);
    }

    public function test_an_inactive_redirect_is_not_applied_and_falls_through_to_404(): void
    {
        $redirect = $this->redirects->create($this->admin, ['source_path' => '/old-page', 'target_path' => '/about-us', 'type' => RedirectType::Permanent]);
        $this->redirects->deactivate($this->admin, $redirect);

        $this->get('/old-page')->assertNotFound();
    }

    public function test_an_unmatched_path_with_no_redirect_still_404s_normally(): void
    {
        $this->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertSee('Page not found');
    }

    public function test_query_string_is_preserved_across_the_redirect(): void
    {
        $this->redirects->create($this->admin, ['source_path' => '/old-page', 'target_path' => '/about-us', 'type' => RedirectType::Permanent]);

        $this->get('/old-page?utm_source=newsletter')
            ->assertRedirect('/about-us?utm_source=newsletter');
    }

    public function test_a_multi_hop_chain_resolves_in_a_single_http_redirect(): void
    {
        $this->redirects->create($this->admin, ['source_path' => '/a', 'target_path' => '/b', 'type' => RedirectType::Permanent]);
        $this->redirects->create($this->admin, ['source_path' => '/b', 'target_path' => '/about-us', 'type' => RedirectType::Permanent]);

        $this->get('/a')->assertRedirect('/about-us');
    }

    public function test_redirects_are_never_applied_inside_the_admin_panel(): void
    {
        // Even if a (rule-violating) redirect somehow existed with an
        // admin-shaped source, a genuine 404 under /admin must never be
        // silently rewritten — the guard short-circuits before any
        // redirect lookup for these paths.
        $this->get('/admin/this-route-does-not-exist')->assertNotFound();
    }

    public function test_redirects_are_never_applied_on_api_requests(): void
    {
        $this->get('/api/this-route-does-not-exist')->assertNotFound();
    }

    public function test_non_get_requests_are_never_redirected(): void
    {
        $this->redirects->create($this->admin, ['source_path' => '/old-page', 'target_path' => '/about-us', 'type' => RedirectType::Permanent]);

        // The catch-all page route only accepts GET, so POST hits the
        // framework's normal 405 handling — our redirect handler only
        // ever intercepts NotFoundHttpException, never
        // MethodNotAllowedHttpException, so this must never redirect.
        $response = $this->post('/old-page');

        $response->assertStatus(405);
        $this->assertFalse($response->isRedirect());
    }
}
