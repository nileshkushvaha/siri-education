<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Dashboard\DTOs\DashboardContext;
use App\Dashboard\DTOs\DashboardData;
use App\Dashboard\Services\AttentionFeedService;
use App\Dashboard\Services\DashboardCompositionService;
use App\Dashboard\Services\DashboardPermissions;
use App\Models\Country;
use App\Models\User;
use App\Reporting\Contracts\StudentEngagementReportServiceInterface;
use App\Reporting\DTOs\Engagement\StudentEngagementSummaryData;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use App\Reporting\Enums\ReportDataFreshness;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Services\StudentEngagementReportService;
use App\Reporting\Support\ReportingTimezoneResolver;
use App\Reporting\ValueObjects\ReportingPeriod;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cache correctness and query economy.
 *
 * The security-critical property is that a cache entry can never cross
 * a permission boundary: the key carries a digest of every permission
 * decision that shapes the output, so two users with different access
 * cannot collide and a permission change invalidates immediately.
 */
class DashboardCachingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
    }

    // ── Honest freshness ─────────────────────────────────────────────

    public function test_composed_dashboard_never_claims_to_be_live(): void
    {
        $freshness = $this->compose($this->manager())->freshness;

        // ReportDataFreshness' own contract: no report may claim Live
        // while reading cached data.
        $this->assertSame(ReportDataFreshness::CachedWithTimestamp, $freshness->freshness);
        $this->assertSame('Cached', $freshness->label());
        $this->assertSame(DashboardCompositionService::CACHE_TTL_SECONDS, $freshness->ttlSeconds);
    }

    public function test_attention_feed_declares_its_own_shorter_freshness(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager);

        $feed = app(AttentionFeedService::class)->build($manager);

        $this->assertSame(ReportDataFreshness::CachedWithTimestamp, $feed->freshness);
        // Urgent counts must not inherit the period section's TTL.
        $this->assertLessThan(
            DashboardCompositionService::CACHE_TTL_SECONDS,
            AttentionFeedService::CACHE_TTL_SECONDS,
        );
        $this->assertSame(60, AttentionFeedService::CACHE_TTL_SECONDS);
    }

    public function test_composition_ttl_matches_the_documented_five_minutes(): void
    {
        $this->assertSame(300, DashboardCompositionService::CACHE_TTL_SECONDS);
    }

    // ── The cache actually caches ────────────────────────────────────

    public function test_a_second_composition_issues_no_further_queries(): void
    {
        $manager = $this->manager();

        $this->compose($manager);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->compose($manager);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Permission lookups may still occur; no reporting-domain query may.
        $sql = strtolower(implode(' | ', array_column($queries, 'query')));

        $this->assertStringNotContainsString('from `bookings`', $sql);
        $this->assertStringNotContainsString('from `lessons`', $sql);
        $this->assertStringNotContainsString('from `wallets`', $sql);
    }

    public function test_refreshing_discards_the_entry(): void
    {
        $manager = $this->manager();
        $context = $this->context();

        $this->actingAs($manager);
        $service = app(DashboardCompositionService::class);
        $service->compose($manager, $context);

        $service->forget($manager, $context);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $service->compose($manager, $context);

        $sql = strtolower(implode(' | ', array_column(DB::getQueryLog(), 'query')));
        DB::disableQueryLog();

        // Recomputed, so the reporting domain is queried again.
        $this->assertStringContainsString('from `bookings`', $sql);
    }

    // ── Cache keys are permission- and period-scoped ─────────────────

    public function test_two_users_with_different_permissions_do_not_share_an_entry(): void
    {
        $manager = $this->manager();

        $limited = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $limited->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));

        // Warm the full manager's dashboard first.
        $full = $this->compose($manager);
        $this->assertNotSame([], $full->kpis);

        // Now strip the shared role's permissions; the limited user's
        // signature differs, so they must not read the warm entry.
        Role::findByName('manager', 'web')->syncPermissions([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame([], $this->compose($limited)->kpis);
    }

    public function test_permission_signature_changes_when_a_permission_is_granted(): void
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $user->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));
        Role::findByName('manager', 'web')->syncPermissions([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = app(DashboardPermissions::class);
        $before = $permissions->signature($user->fresh());

        $user->givePermissionTo('ViewStudentReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $after = $permissions->signature($user->fresh());

        $this->assertNotSame($before, $after);
    }

    public function test_different_periods_use_different_entries(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager);

        $service = app(DashboardCompositionService::class);

        $service->compose($manager, $this->context(ReportingPeriodPreset::Last30Days));

        DB::enableQueryLog();
        DB::flushQueryLog();

        $service->compose($manager, $this->context(ReportingPeriodPreset::ThisMonth));

        $sql = strtolower(implode(' | ', array_column(DB::getQueryLog(), 'query')));
        DB::disableQueryLog();

        // A different period must not be served the first period's data.
        $this->assertStringContainsString('from `bookings`', $sql);
    }

    public function test_different_countries_use_different_entries(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager);
        $country = Country::factory()->create();

        $service = app(DashboardCompositionService::class);
        $service->compose($manager, $this->context());

        DB::enableQueryLog();
        DB::flushQueryLog();

        $service->compose($manager, new DashboardContext(
            period: ReportingPeriod::forPreset(ReportingPeriodPreset::Last30Days, ReportingTimezoneResolver::resolve()),
            countryId: $country->id,
        ));

        $sql = strtolower(implode(' | ', array_column(DB::getQueryLog(), 'query')));
        DB::disableQueryLog();

        $this->assertStringContainsString('from `bookings`', $sql);
    }

    // ── The cache stores primitives, never objects ───────────────────

    public function test_the_cached_payload_contains_no_objects(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager);

        $service = app(DashboardCompositionService::class);
        $service->compose($manager, $this->context());

        $payload = $this->cachedPayload($manager);

        $this->assertIsArray($payload);
        // Storing DTOs would serialize class instances into the cache
        // store — and this application's store is `database`, which
        // survives deploys. A payload written before a DTO's constructor
        // changed then rehydrates as __PHP_Incomplete_Class and keeps
        // being served until the TTL expires.
        $this->assertNoObjectsIn($payload, 'payload');
    }

    public function test_a_payload_from_a_different_build_is_discarded_not_hydrated(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager);

        $service = app(DashboardCompositionService::class);
        $service->compose($manager, $this->context());

        // Simulate a deploy that changed the payload shape.
        $key = $this->cacheKeyFor($manager);
        $stale = cache()->get($key);
        $stale['version'] = 'written-by-an-older-build';
        cache()->put($key, $stale, 300);

        // Must recompose rather than throw.
        $data = $service->compose($manager, $this->context());

        $this->assertNotSame([], $data->kpis);
        $this->assertSame(
            'v1',
            $this->cachedPayload($manager)['version'],
            'The stale entry must have been replaced.',
        );
    }

    public function test_a_corrupt_cached_payload_is_rebuilt_rather_than_fatal(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager);

        $service = app(DashboardCompositionService::class);
        $service->compose($manager, $this->context());

        // The exact production failure this guards: an entry the current
        // build cannot read at all.
        cache()->put($this->cacheKeyFor($manager), ['version' => 'v1', 'kpis' => 'not-an-array'], 300);

        $data = $service->compose($manager, $this->context());

        $this->assertNotSame([], $data->kpis);
    }

    public function test_attention_feed_also_caches_primitives_only(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager);

        app(AttentionFeedService::class)->build($manager);

        $key = sprintf(
            'dashboard:attention:%s:%s',
            $manager->getKey(),
            app(DashboardPermissions::class)->signature($manager),
        );

        $payload = cache()->get($key);

        $this->assertIsArray($payload);
        $this->assertIsArray($payload['items']);
        $this->assertNoObjectsIn($payload, 'attention payload');
    }

    // ── One call per owning service ──────────────────────────────────

    public function test_cards_sharing_one_report_dto_do_not_trigger_duplicate_service_calls(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager);

        // Two KPIs (engaged students, new students) plus a chart
        // (registration trend) all read student-engagement data. The
        // owning service must be consulted exactly once per composition,
        // not once per card.
        //
        // A counting decorator is used rather than a Mockery spy because
        // several report services type-hint this interface on their own
        // constructors, so the substitute has to be a genuine
        // implementation.
        $counter = new CountingStudentEngagementService(
            app(StudentEngagementReportService::class),
        );
        $this->app->instance(StudentEngagementReportServiceInterface::class, $counter);

        app(DashboardCompositionService::class)->compose($manager, $this->context());

        $this->assertSame(1, $counter->calls['summary'] ?? 0, 'summary() must be called exactly once.');
        $this->assertSame(1, $counter->calls['registrationTrend'] ?? 0, 'registrationTrend() must be called exactly once.');
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function compose(User $user): DashboardData
    {
        $this->actingAs($user);

        return app(DashboardCompositionService::class)->compose($user, $this->context());
    }

    private function context(?ReportingPeriodPreset $preset = null): DashboardContext
    {
        return new DashboardContext(
            period: ReportingPeriod::forPreset(
                $preset ?? ReportingPeriodPreset::Last30Days,
                ReportingTimezoneResolver::resolve(),
            ),
            countryId: null,
        );
    }

    /** @return array<string, mixed> */
    private function cachedPayload(User $user): array
    {
        return cache()->get($this->cacheKeyFor($user));
    }

    private function cacheKeyFor(User $user): string
    {
        $context = $this->context();

        return sprintf(
            'dashboard:composition:%s:%s:%s',
            $user->getKey(),
            app(DashboardPermissions::class)->signature($user),
            substr(hash('sha256', $context->cacheFragment()), 0, 24),
        );
    }

    private function assertNoObjectsIn(mixed $value, string $path): void
    {
        if (is_object($value)) {
            $this->fail("Cached {$path} contains an object (".$value::class.'); only primitives may be cached.');
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->assertNoObjectsIn($item, $path.'.'.$key);
            }
        }
    }

    private function manager(): User
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $user->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));

        return $user;
    }
}

/**
 * Counts calls while delegating to the real service, so the assertion
 * measures composition behaviour rather than a mock's expectations.
 *
 * A hand-written decorator is used instead of a Mockery spy because
 * several report services type-hint this interface on their own
 * constructors, so the substitute must be a genuine implementation that
 * the container can inject everywhere.
 */
final class CountingStudentEngagementService implements StudentEngagementReportServiceInterface
{
    /** @var array<string, int> */
    public array $calls = [];

    public function __construct(private readonly StudentEngagementReportServiceInterface $inner) {}

    public function summary(User $user, ReportingPeriod $period, ReportFilters $filters): StudentEngagementSummaryData
    {
        $this->record(__FUNCTION__);

        return $this->inner->summary($user, $period, $filters);
    }

    public function byCountry(User $user, ReportingPeriod $period, ReportFilters $filters): array
    {
        $this->record(__FUNCTION__);

        return $this->inner->byCountry($user, $period, $filters);
    }

    public function byAcademicLevel(User $user, ReportingPeriod $period, ReportFilters $filters): array
    {
        $this->record(__FUNCTION__);

        return $this->inner->byAcademicLevel($user, $period, $filters);
    }

    public function byPreferredSubject(User $user, ReportingPeriod $period, ReportFilters $filters): array
    {
        $this->record(__FUNCTION__);

        return $this->inner->byPreferredSubject($user, $period, $filters);
    }

    public function byBookedSubject(User $user, ReportingPeriod $period, ReportFilters $filters): array
    {
        $this->record(__FUNCTION__);

        return $this->inner->byBookedSubject($user, $period, $filters);
    }

    public function registrationTrend(User $user, ReportingPeriod $period, ReportFilters $filters): array
    {
        $this->record(__FUNCTION__);

        return $this->inner->registrationTrend($user, $period, $filters);
    }

    public function engagementRows(User $user, ReportingPeriod $period, ReportFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        $this->record(__FUNCTION__);

        return $this->inner->engagementRows($user, $period, $filters, $perPage);
    }

    public function freshness(ReportingPeriod $period): OperationsReportFreshnessData
    {
        $this->record(__FUNCTION__);

        return $this->inner->freshness($period);
    }

    public function canView(User $user): bool
    {
        return $this->inner->canView($user);
    }

    private function record(string $method): void
    {
        $this->calls[$method] = ($this->calls[$method] ?? 0) + 1;
    }
}
