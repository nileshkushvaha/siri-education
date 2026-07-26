<?php

declare(strict_types=1);

namespace Tests\Feature\Redirects;

use App\Content\Redirects\Enums\RedirectType;
use App\Content\Redirects\Exceptions\RedirectException;
use App\Content\Redirects\Services\RedirectService;
use App\Models\Redirect;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * GAP-036 (SRS §22.25/26): RedirectService is the sole authoritative
 * boundary for redirect CRUD, normalization, duplicate/loop/target
 * validation, and resolution.
 */
final class RedirectServiceTest extends TestCase
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

    // ── Normalization and uniqueness ──────────────────────────────────

    public function test_paths_are_normalized_on_create(): void
    {
        $redirect = $this->redirects->create($this->admin, [
            'source_path' => 'Old-Page/',
            'target_path' => '/New-Page',
            'type' => RedirectType::Permanent,
        ]);

        $this->assertSame('/old-page', $redirect->source_path);
        $this->assertSame('/new-page', $redirect->target_path);
    }

    public function test_full_urls_pasted_as_source_are_reduced_to_their_path(): void
    {
        $redirect = $this->redirects->create($this->admin, [
            'source_path' => config('app.url').'/old-legal-page',
            'target_path' => '/legal/terms',
            'type' => RedirectType::Permanent,
        ]);

        $this->assertSame('/old-legal-page', $redirect->source_path);
    }

    public function test_duplicate_active_source_is_rejected(): void
    {
        $this->redirects->create($this->admin, ['source_path' => '/old', 'target_path' => '/new-1', 'type' => RedirectType::Permanent]);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessage('already exists');

        $this->redirects->create($this->admin, ['source_path' => '/old', 'target_path' => '/new-2', 'type' => RedirectType::Permanent]);
    }

    public function test_a_deactivated_source_can_be_reused_by_a_new_active_redirect(): void
    {
        $first = $this->redirects->create($this->admin, ['source_path' => '/old', 'target_path' => '/new-1', 'type' => RedirectType::Permanent]);
        $this->redirects->deactivate($this->admin, $first);

        $second = $this->redirects->create($this->admin, ['source_path' => '/old', 'target_path' => '/new-2', 'type' => RedirectType::Permanent]);

        $this->assertTrue($second->is_active);
        $this->assertSame(2, Redirect::query()->where('source_path', '/old')->count());
    }

    public function test_reactivating_a_redirect_that_would_now_duplicate_an_active_source_is_rejected(): void
    {
        $first = $this->redirects->create($this->admin, ['source_path' => '/old', 'target_path' => '/new-1', 'type' => RedirectType::Permanent]);
        $this->redirects->deactivate($this->admin, $first);
        $this->redirects->create($this->admin, ['source_path' => '/old', 'target_path' => '/new-2', 'type' => RedirectType::Permanent]);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessage('already exists');

        $this->redirects->activate($this->admin, $first->fresh());
    }

    public function test_the_database_constraint_independently_rejects_a_duplicate_active_source(): void
    {
        // Proves the guarantee is a real DB constraint, not just the
        // service's application-level pre-check: two raw inserts that
        // both bypass RedirectService (as two racing requests' pre-checks
        // would each see no conflict yet) still cannot both succeed.
        DB::table('redirects')->insert([
            'id' => (string) Str::uuid(),
            'source_path' => '/old',
            'target_path' => '/new-1',
            'type' => '301',
            'is_active' => true,
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('redirects')->insert([
            'id' => (string) Str::uuid(),
            'source_path' => '/old',
            'target_path' => '/new-2',
            'type' => '301',
            'is_active' => true,
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_service_create_translates_the_database_constraint_into_a_redirect_exception(): void
    {
        // Same scenario, but through RedirectService::create() — its
        // pre-check would normally catch this, but this asserts the
        // QueryException-translation fallback path also produces the
        // same friendly exception if the pre-check is ever bypassed.
        DB::table('redirects')->insert([
            'id' => (string) Str::uuid(),
            'source_path' => '/old',
            'target_path' => '/new-1',
            'type' => '301',
            'is_active' => true,
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessage('already exists');

        $this->redirects->create($this->admin, ['source_path' => '/old', 'target_path' => '/new-2', 'type' => RedirectType::Permanent]);
    }

    // ── Direct/indirect loop prevention ───────────────────────────────

    public function test_source_equal_to_target_is_rejected(): void
    {
        $this->expectException(RedirectException::class);
        $this->expectExceptionMessage('itself');

        $this->redirects->create($this->admin, ['source_path' => '/same', 'target_path' => '/same', 'type' => RedirectType::Permanent]);
    }

    public function test_direct_loop_via_two_redirects_is_rejected(): void
    {
        $this->redirects->create($this->admin, ['source_path' => '/a', 'target_path' => '/b', 'type' => RedirectType::Permanent]);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessage('loop');

        $this->redirects->create($this->admin, ['source_path' => '/b', 'target_path' => '/a', 'type' => RedirectType::Permanent]);
    }

    public function test_indirect_loop_across_three_redirects_is_rejected(): void
    {
        $this->redirects->create($this->admin, ['source_path' => '/a', 'target_path' => '/b', 'type' => RedirectType::Permanent]);
        $this->redirects->create($this->admin, ['source_path' => '/b', 'target_path' => '/c', 'type' => RedirectType::Permanent]);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessage('loop');

        $this->redirects->create($this->admin, ['source_path' => '/c', 'target_path' => '/a', 'type' => RedirectType::Permanent]);
    }

    public function test_non_looping_chains_are_allowed_to_be_created(): void
    {
        $this->redirects->create($this->admin, ['source_path' => '/a', 'target_path' => '/b', 'type' => RedirectType::Permanent]);
        $c = $this->redirects->create($this->admin, ['source_path' => '/b', 'target_path' => '/c', 'type' => RedirectType::Permanent]);

        $this->assertSame('/c', $c->target_path);
        $this->assertSame(2, Redirect::query()->count());
    }

    public function test_editing_a_redirect_into_a_loop_is_rejected(): void
    {
        $this->redirects->create($this->admin, ['source_path' => '/a', 'target_path' => '/b', 'type' => RedirectType::Permanent]);
        $bToC = $this->redirects->create($this->admin, ['source_path' => '/b', 'target_path' => '/c', 'type' => RedirectType::Permanent]);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessage('loop');

        $this->redirects->update($this->admin, $bToC, ['target_path' => '/a']);
    }

    // ── Unsafe target rejection ────────────────────────────────────────

    public function test_protocol_relative_target_is_rejected(): void
    {
        $this->expectException(RedirectException::class);
        $this->expectExceptionMessage('Protocol-relative');

        $this->redirects->create($this->admin, ['source_path' => '/x', 'target_path' => '//evil.example.com/phish', 'type' => RedirectType::Permanent]);
    }

    public function test_external_absolute_target_is_rejected(): void
    {
        $this->expectException(RedirectException::class);
        $this->expectExceptionMessage('External');

        $this->redirects->create($this->admin, ['source_path' => '/x', 'target_path' => 'https://evil.example.com/phish', 'type' => RedirectType::Permanent]);
    }

    public function test_unsafe_scheme_target_is_rejected(): void
    {
        $this->expectException(RedirectException::class);
        $this->expectExceptionMessage('scheme');

        $this->redirects->create($this->admin, ['source_path' => '/x', 'target_path' => 'javascript:alert(1)', 'type' => RedirectType::Permanent]);
    }

    public function test_malformed_target_path_is_rejected(): void
    {
        $this->expectException(RedirectException::class);

        $this->redirects->create($this->admin, ['source_path' => '/x', 'target_path' => '/../../etc/passwd', 'type' => RedirectType::Permanent]);
    }

    // ── Protected-route exclusion ────────────────────────────────────

    public function test_target_pointing_at_admin_is_rejected(): void
    {
        $this->expectException(RedirectException::class);
        $this->expectExceptionMessage('not allowed');

        $this->redirects->create($this->admin, ['source_path' => '/x', 'target_path' => '/admin/dashboard', 'type' => RedirectType::Permanent]);
    }

    public function test_target_pointing_at_api_is_rejected(): void
    {
        $this->expectException(RedirectException::class);

        $this->redirects->create($this->admin, ['source_path' => '/x', 'target_path' => '/api/anything', 'type' => RedirectType::Permanent]);
    }

    public function test_target_pointing_at_login_route_is_rejected(): void
    {
        $this->expectException(RedirectException::class);

        $this->redirects->create($this->admin, ['source_path' => '/x', 'target_path' => '/login', 'type' => RedirectType::Permanent]);
    }

    public function test_target_pointing_at_an_authenticated_dashboard_route_is_rejected(): void
    {
        $this->expectException(RedirectException::class);

        $this->redirects->create($this->admin, ['source_path' => '/x', 'target_path' => '/dashboard/homework', 'type' => RedirectType::Permanent]);
    }

    public function test_target_pointing_at_storage_is_rejected(): void
    {
        $this->expectException(RedirectException::class);

        $this->redirects->create($this->admin, ['source_path' => '/x', 'target_path' => '/storage/some-file.pdf', 'type' => RedirectType::Permanent]);
    }

    public function test_target_pointing_at_a_public_cms_page_slug_is_allowed(): void
    {
        $redirect = $this->redirects->create($this->admin, ['source_path' => '/x', 'target_path' => '/about-us', 'type' => RedirectType::Permanent]);

        $this->assertSame('/about-us', $redirect->target_path);
    }

    // ── Resolution / 301 vs 302 / inactive ────────────────────────────

    public function test_resolve_returns_the_target_and_status_for_an_active_redirect(): void
    {
        $this->redirects->create($this->admin, ['source_path' => '/old', 'target_path' => '/new', 'type' => RedirectType::Permanent]);

        $resolution = $this->redirects->resolve('/old');

        $this->assertSame(['url' => '/new', 'status' => 301], $resolution);
    }

    public function test_resolve_reports_302_for_a_temporary_redirect(): void
    {
        $this->redirects->create($this->admin, ['source_path' => '/old', 'target_path' => '/new', 'type' => RedirectType::Temporary]);

        $resolution = $this->redirects->resolve('/old');

        $this->assertSame(302, $resolution['status']);
    }

    public function test_resolve_returns_null_for_an_inactive_redirect(): void
    {
        $redirect = $this->redirects->create($this->admin, ['source_path' => '/old', 'target_path' => '/new', 'type' => RedirectType::Permanent]);
        $this->redirects->deactivate($this->admin, $redirect);

        $this->assertNull($this->redirects->resolve('/old'));
    }

    public function test_resolve_returns_null_for_an_unknown_path(): void
    {
        $this->assertNull($this->redirects->resolve('/never-existed'));
    }

    public function test_resolve_follows_a_chain_to_its_final_destination_in_one_hop(): void
    {
        $this->redirects->create($this->admin, ['source_path' => '/a', 'target_path' => '/b', 'type' => RedirectType::Permanent]);
        $this->redirects->create($this->admin, ['source_path' => '/b', 'target_path' => '/c', 'type' => RedirectType::Permanent]);

        $resolution = $this->redirects->resolve('/a');

        $this->assertSame('/c', $resolution['url']);
    }

    public function test_resolve_normalizes_the_incoming_path_before_lookup(): void
    {
        $this->redirects->create($this->admin, ['source_path' => '/old-page', 'target_path' => '/new-page', 'type' => RedirectType::Permanent]);

        $this->assertSame('/new-page', $this->redirects->resolve('Old-Page/')['url']);
    }

    // ── Authorization and audit ────────────────────────────────────────

    public function test_create_update_activate_deactivate_are_all_audited(): void
    {
        $redirect = $this->redirects->create($this->admin, ['source_path' => '/old', 'target_path' => '/new', 'type' => RedirectType::Permanent]);
        $this->redirects->update($this->admin, $redirect, ['target_path' => '/new-2']);
        $this->redirects->deactivate($this->admin, $redirect->fresh());
        $this->redirects->activate($this->admin, $redirect->fresh());

        foreach (['redirect_created', 'redirect_updated', 'redirect_deactivated', 'redirect_activated'] as $event) {
            $this->assertSame(
                1,
                Activity::query()->where('log_name', 'redirects')->where('event', $event)->count(),
                "Expected exactly one {$event} audit entry.",
            );
        }
    }

    public function test_target_change_is_flagged_in_the_update_audit_entry(): void
    {
        $redirect = $this->redirects->create($this->admin, ['source_path' => '/old', 'target_path' => '/new', 'type' => RedirectType::Permanent]);
        $this->redirects->update($this->admin, $redirect, ['target_path' => '/new-2']);

        $activity = Activity::query()->where('log_name', 'redirects')->where('event', 'redirect_updated')->sole();

        $this->assertTrue($activity->properties['target_changed']);
    }

    // ── Bounded lookup ───────────────────────────────────────────────

    public function test_resolution_query_stays_bounded_regardless_of_table_size(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->redirects->create($this->admin, ['source_path' => "/old-{$i}", 'target_path' => "/new-{$i}", 'type' => RedirectType::Permanent]);
        }

        DB::enableQueryLog();
        $this->redirects->resolve('/old-10');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // One indexed lookup for the source itself, plus exactly one
        // more to check whether the resolved target is itself the
        // source of a further active redirect (the chain-following
        // check) — fixed at 2 regardless of how many redirects exist.
        $this->assertSame(2, $count);
    }
}
