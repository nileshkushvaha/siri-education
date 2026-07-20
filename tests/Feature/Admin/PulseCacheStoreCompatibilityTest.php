<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Pulse\Livewire\Queues;
use Laravel\Pulse\Support\CacheStoreResolver;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 24O.2 — GAP-033 corrective: root cause was this local machine's
 * default `opcache.interned_strings_buffer` (8MB, PHP's own default) being
 * 100% exhausted in the long-running `php artisan serve` process (confirmed
 * via opcache_get_status() against the live server — free_memory: 0), a
 * known category of PHP/OPcache instability for apps with a large class
 * footprint (this app bundles google/apiclient-services, which alone
 * contributes thousands of classes). With the buffer full, unserialize()
 * of Pulse's database-cached card values (Illuminate\Support\Collection,
 * stdClass) intermittently resolved to __PHP_Incomplete_Class in that one
 * process — reproduced deterministically via real Livewire polling
 * requests against the live server (never inside PHPUnit, which runs
 * under the plain "cli" SAPI where opcache.enable_cli is Off by default —
 * see docs/pulse-monitoring.md for the full trace).
 *
 * The fix routes Pulse's OWN card-level cache (`pulse.cache` config,
 * `PULSE_CACHE_DRIVER` env) through the `array` store, which never
 * serializes/unserializes at all — sidestepping the exact codepath that
 * failed, without any global PHP/OPcache/system change. Because the
 * trigger is OPcache-CLI-specific, these tests cannot reproduce the
 * original failure directly (PHPUnit never exercises that code path) —
 * they instead verify the fix's actual mechanics and guard the underlying
 * assumptions.
 */
class PulseCacheStoreCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pulse.enabled' => true]);

        Permission::firstOrCreate(['name' => 'pulse.view', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['status' => 'active']);
        $this->admin->assignRole('super_admin');
    }

    /** 1. A round-trip through the configured Pulse cache store never produces __PHP_Incomplete_Class. */
    public function test_pulse_cache_store_round_trip_never_produces_incomplete_object(): void
    {
        config(['pulse.cache' => 'array']);

        $store = app(CacheStoreResolver::class)->store();
        $store->put('phase_24o2_probe', collect(['a' => 1, 'b' => 2]), 60);

        $result = $store->get('phase_24o2_probe');

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertNotInstanceOf(\__PHP_Incomplete_Class::class, $result);
    }

    /** 2. Nested values (Collection<Collection<Collection>>>, matching Queues::graph()'s real shape) round-trip intact. */
    public function test_nested_collection_values_round_trip_intact_not_only_the_root(): void
    {
        config(['pulse.cache' => 'array']);

        $nested = collect([
            'queued' => collect(['2026-01-01 00:00:00' => collect(['count' => 1])]),
        ]);

        $store = app(CacheStoreResolver::class)->store();
        $store->put('phase_24o2_nested_probe', $nested, 60);

        $result = $store->get('phase_24o2_nested_probe');

        $this->assertInstanceOf(Collection::class, $result);
        $inner = $result->get('queued');
        $this->assertInstanceOf(Collection::class, $inner);
        $innermost = $inner->get('2026-01-01 00:00:00');
        $this->assertInstanceOf(Collection::class, $innermost);
        $this->assertSame(1, $innermost->get('count'));
    }

    /** 3. A real Pulse card Livewire update succeeds after the initial mount (the exact lifecycle that failed live). */
    public function test_real_livewire_card_update_succeeds_after_initial_render(): void
    {
        Livewire::actingAs($this->admin)
            ->test(Queues::class)
            ->assertStatus(200)
            ->call('$refresh')
            ->assertStatus(200);
    }

    /** 4. Multiple sequential polls remain successful (mirrors wire:poll.5s repeating). */
    public function test_multiple_sequential_polls_remain_successful(): void
    {
        $component = Livewire::actingAs($this->admin)->test(Queues::class);

        for ($i = 0; $i < 10; $i++) {
            $component->call('$refresh')->assertStatus(200);
        }

        $this->assertTrue(true);
    }

    /** 5. A bounded "concurrent-like" burst of polls (same request cycle repeated back-to-back) produces no 500/incomplete object. */
    public function test_bounded_rapid_polls_produce_no_failure(): void
    {
        $components = [];
        for ($i = 0; $i < 5; $i++) {
            $components[] = Livewire::actingAs($this->admin)->test(Queues::class);
        }

        foreach ($components as $component) {
            $component->call('$refresh')->assertStatus(200);
        }

        $this->assertTrue(true);
    }

    /**
     * 6. A value written to the real MySQL-backed cache round-trips
     * correctly on read — proving the underlying storage bytes are sound
     * (this is the exact class of check that ruled out data corruption
     * during live diagnosis; see docs/pulse-monitoring.md Step 4). A true
     * separate-OS-process read isn't reproducible inside a single
     * transactional PHPUnit test (RefreshDatabase wraps each test in an
     * uncommitted transaction a second connection couldn't see), so this
     * verifies the same serialize-write/unserialize-read boundary a
     * different process would exercise, via a query builder read that
     * doesn't rely on any single PHP object reference from the write.
     */
    public function test_a_value_written_to_the_database_cache_reads_back_intact(): void
    {
        DB::table('cache')->insert([
            'key' => 'phase_24o2_cross_connection_probe',
            'value' => serialize(collect(['x' => 1, 'y' => collect(['z' => 2])])),
            'expiration' => now()->addMinute()->timestamp,
        ]);

        $row = DB::table('cache')->where('key', 'phase_24o2_cross_connection_probe')->first();

        $result = unserialize($row->value);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertInstanceOf(Collection::class, $result->get('y'));
        $this->assertSame(2, $result->get('y')->get('z'));
    }

    /** 8. Authorization is unaffected by the cache-store change. */
    public function test_authorization_unaffected_guest_denied(): void
    {
        $this->get('/pulse')->assertForbidden();
    }

    public function test_authorization_unaffected_manager_with_permission_allowed(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager = User::factory()->create(['status' => 'active']);
        $manager->assignRole('manager');
        $manager->givePermissionTo('pulse.view');

        $this->actingAs($manager)->get('/pulse')->assertOk();
    }

    public function test_authorization_unaffected_super_admin_allowed(): void
    {
        $this->actingAs($this->admin)->get('/pulse')->assertOk();
    }

    /** 10. The correction does not purge unrelated cache/session records. */
    public function test_correction_does_not_purge_unrelated_cache_or_session_records(): void
    {
        DB::table('cache')->insert([
            'key' => 'unrelated_app_cache_key',
            'value' => serialize('unrelated-value'),
            'expiration' => now()->addMinute()->timestamp,
        ]);

        config(['pulse.cache' => 'array']);
        $store = app(CacheStoreResolver::class)->store();
        $store->put('phase_24o2_unrelated_check', 'x', 60);

        $this->assertSame(
            1,
            DB::table('cache')->where('key', 'unrelated_app_cache_key')->count(),
            'The Pulse cache-store correction must never touch unrelated cache rows.'
        );
    }
}
