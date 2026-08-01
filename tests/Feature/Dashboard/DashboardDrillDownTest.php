<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Dashboard\DTOs\DashboardContext;
use App\Dashboard\Services\AttentionFeedService;
use App\Dashboard\Services\DashboardCompositionService;
use App\Dashboard\Support\DashboardUrl;
use App\Filament\Pages\BookingLessonMeetingOperations;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\LearningAnalytics;
use App\Filament\Pages\StudentEngagement;
use App\Filament\Resources\InstructorOnboarding\InstructorOnboardingResource;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Filament\Resources\OperationalAlerts\OperationalAlertResource;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Enums\LessonStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Lesson;
use App\Models\User;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Support\ReportingTimezoneResolver;
use App\Reporting\Support\ReportPeriodResolver;
use App\Reporting\ValueObjects\ReportingPeriod;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Filter context and drill-down connectivity.
 *
 * Two destination kinds behave differently and both are covered:
 * Filament resource indexes carry `?filters[...]`/`?tab=` natively,
 * while report pages now declare their own `#[Url]` bindings so the
 * dashboard's period and country survive the jump.
 */
class DashboardDrillDownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
    }

    // ── Default and validated period ─────────────────────────────────

    public function test_dashboard_defaults_to_last_thirty_days(): void
    {
        Livewire::actingAs($this->manager())
            ->test(Dashboard::class)
            ->assertSet('periodPreset', ReportingPeriodPreset::Last30Days->value);
    }

    public function test_context_resolves_the_default_preset(): void
    {
        $this->actingAs($this->manager());

        $context = (new Dashboard)->context();

        $this->assertSame(ReportingPeriodPreset::Last30Days, $context->period->preset);
    }

    public function test_an_unknown_period_value_falls_back_safely(): void
    {
        $period = ReportPeriodResolver::resolve('not-a-preset');

        $this->assertSame(ReportingPeriodPreset::Last30Days, $period->preset);
    }

    public function test_a_custom_range_wider_than_the_maximum_falls_back_instead_of_throwing(): void
    {
        $tooWide = ReportingPeriod::MAX_CUSTOM_RANGE_DAYS + 5;

        $period = ReportPeriodResolver::resolve(
            preset: 'custom',
            customStart: now()->subDays($tooWide)->toDateString(),
            customEnd: now()->toDateString(),
        );

        $this->assertSame(ReportingPeriodPreset::Last30Days, $period->preset);
        $this->assertTrue(ReportPeriodResolver::customRangeIsInvalid(
            'custom',
            now()->subDays($tooWide)->toDateString(),
            now()->toDateString(),
        ));
    }

    public function test_an_end_before_start_custom_range_falls_back(): void
    {
        $period = ReportPeriodResolver::resolve(
            preset: 'custom',
            customStart: now()->toDateString(),
            customEnd: now()->subDays(10)->toDateString(),
        );

        $this->assertSame(ReportingPeriodPreset::Last30Days, $period->preset);
    }

    public function test_an_unparseable_custom_date_falls_back(): void
    {
        $period = ReportPeriodResolver::resolve('custom', 'banana', 'kiwi');

        $this->assertSame(ReportingPeriodPreset::Last30Days, $period->preset);
    }

    public function test_a_valid_custom_range_is_honoured(): void
    {
        $period = ReportPeriodResolver::resolve(
            preset: 'custom',
            customStart: '2026-01-01',
            customEnd: '2026-01-31',
        );

        $this->assertSame(ReportingPeriodPreset::Custom, $period->preset);
        $this->assertSame('2026-01-01', $period->start->toDateString());
    }

    public function test_period_boundaries_use_the_reporting_timezone(): void
    {
        $period = ReportPeriodResolver::resolve('today');

        $this->assertSame(ReportingTimezoneResolver::resolve(), $period->timezone);
        // Half-open interval, DST-proof, as ReportingPeriod requires.
        $this->assertTrue($period->endUtcExclusive->greaterThan($period->startUtc));
    }

    // ── Country filter is validated ──────────────────────────────────

    public function test_an_unknown_country_id_is_dropped(): void
    {
        $this->actingAs($this->manager());

        $page = new Dashboard;
        $page->countryId = '999999';

        $this->assertNull($page->context()->countryId);
    }

    public function test_a_registerable_country_id_is_honoured(): void
    {
        $this->actingAs($this->manager());
        $country = $this->registerableCountry();

        $page = new Dashboard;
        $page->countryId = (string) $country->id;

        $this->assertSame($country->id, $page->context()->countryId);
    }

    public function test_a_country_nobody_can_register_in_is_not_offered_or_honoured(): void
    {
        $this->actingAs($this->manager());

        // Active, but its default currency is inactive — registration
        // could never resolve a billing currency for it, so filtering the
        // dashboard by it would only ever return nothing.
        $currency = Currency::query()->create([
            'code' => 'XTS', 'name' => 'Test Currency', 'symbol' => 'X',
            'minor_units' => 2, 'status' => 'inactive',
        ]);
        $country = Country::factory()->create(['default_currency_id' => $currency->id]);

        $page = new Dashboard;
        $page->countryId = (string) $country->id;

        $this->assertNull($page->context()->countryId);
        $this->assertFalse($page->countryOptions()->contains('id', $country->id));
    }

    public function test_the_dashboard_offers_exactly_the_registration_country_list(): void
    {
        $this->actingAs($this->manager());

        $offered = $this->registerableCountry();

        $inactiveCurrency = Currency::query()->create([
            'code' => 'XTY', 'name' => 'Dormant', 'symbol' => 'Y',
            'minor_units' => 2, 'status' => 'inactive',
        ]);
        $hidden = Country::factory()->create(['default_currency_id' => $inactiveCurrency->id]);
        $noCurrency = Country::factory()->create(['default_currency_id' => null]);
        $suspended = Country::factory()->create([
            'status' => 'inactive',
            'default_currency_id' => $offered->default_currency_id,
        ]);

        // The registration form's own source of truth.
        $registration = Country::query()->availableForRegistration()->pluck('id')->sort()->values();
        $dashboard = (new Dashboard)->countryOptions()->pluck('id')->sort()->values();

        $this->assertEquals($registration->all(), $dashboard->all());
        $this->assertTrue($dashboard->contains($offered->id));

        foreach ([$hidden, $noCurrency, $suspended] as $excluded) {
            $this->assertFalse($dashboard->contains($excluded->id));
        }
    }

    /** A country the public registration form would actually offer. */
    private function registerableCountry(): Country
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'INR'],
            ['name' => 'Indian Rupee', 'symbol' => '₹', 'minor_units' => 2, 'status' => 'active'],
        );

        return Country::factory()->create(['default_currency_id' => $currency->id]);
    }

    public function test_a_non_numeric_country_id_is_dropped(): void
    {
        $this->actingAs($this->manager());

        $page = new Dashboard;
        $page->countryId = 'drop table';

        $this->assertNull($page->context()->countryId);
    }

    // ── Report URLs preserve dashboard context ───────────────────────

    public function test_report_urls_carry_the_period(): void
    {
        $context = $this->context(ReportingPeriodPreset::ThisMonth);

        $this->assertStringContainsString('period=this_month', DashboardUrl::studentEngagement($context));
        $this->assertStringContainsString('period=this_month', DashboardUrl::operations($context));
        $this->assertStringContainsString('period=this_month', DashboardUrl::marketplace($context));
    }

    public function test_report_urls_carry_a_custom_range(): void
    {
        $context = new DashboardContext(
            period: ReportingPeriod::custom('2026-02-01', '2026-02-10', 'UTC'),
            countryId: null,
        );

        $url = DashboardUrl::operations($context);

        $this->assertStringContainsString('period=custom', $url);
        $this->assertStringContainsString('start=2026-02-01', $url);
        $this->assertStringContainsString('end=2026-02-10', $url);
    }

    public function test_country_is_forwarded_only_to_reports_that_support_it(): void
    {
        $country = Country::factory()->create();
        $context = new DashboardContext(
            period: ReportingPeriod::forPreset(ReportingPeriodPreset::Last30Days, 'UTC'),
            countryId: $country->id,
        );

        // Student Engagement, Operations and Marketplace all declare
        // Country support in the report registry.
        $this->assertStringContainsString('country='.$country->id, DashboardUrl::studentEngagement($context));
        $this->assertStringContainsString('country='.$country->id, DashboardUrl::operations($context));

        // Learning Analytics does not — forwarding it would pretend a
        // filter applied that the report would ignore.
        $this->assertStringNotContainsString('country=', DashboardUrl::learningAnalytics($context));
        $this->assertStringNotContainsString('country=', DashboardUrl::payments($context));
    }

    public function test_lesson_outcome_drill_down_carries_the_outcome(): void
    {
        $url = DashboardUrl::operations($this->context(), ['lesson_outcome' => LessonOutcome::StudentNoShow->value]);

        $this->assertStringContainsString('lesson_outcome=student_no_show', $url);
    }

    // ── Report pages accept the URL state ────────────────────────────

    public function test_operations_report_adopts_period_and_outcome_from_the_url(): void
    {
        $this->actingAs($this->manager())
            ->get(BookingLessonMeetingOperations::getUrl([
                'period' => 'this_month',
                'lesson_outcome' => LessonOutcome::StudentNoShow->value,
            ]))
            ->assertOk();

        Livewire::actingAs($this->manager())
            ->withQueryParams(['period' => 'this_month', 'lesson_outcome' => 'student_no_show'])
            ->test(BookingLessonMeetingOperations::class)
            ->assertSet('periodPreset', 'this_month')
            ->assertSet('lessonOutcome', 'student_no_show');
    }

    public function test_student_engagement_adopts_period_and_country_from_the_url(): void
    {
        $country = Country::factory()->create();

        Livewire::actingAs($this->manager())
            ->withQueryParams(['period' => 'last_7_days', 'country' => (string) $country->id])
            ->test(StudentEngagement::class)
            ->assertSet('periodPreset', 'last_7_days')
            ->assertSet('countryId', (string) $country->id);
    }

    public function test_a_report_page_survives_an_invalid_enum_in_the_url(): void
    {
        // The raw value is bound, but resolving it must not throw and must
        // not widen the report.
        $this->actingAs($this->manager())
            ->get(BookingLessonMeetingOperations::getUrl(['lesson_outcome' => 'not-an-outcome']))
            ->assertOk();
    }

    public function test_a_report_page_survives_an_oversized_custom_range_in_the_url(): void
    {
        $tooWide = ReportingPeriod::MAX_CUSTOM_RANGE_DAYS + 30;

        $this->actingAs($this->manager())
            ->get(StudentEngagement::getUrl([
                'period' => 'custom',
                'start' => now()->subDays($tooWide)->toDateString(),
                'end' => now()->toDateString(),
            ]))
            ->assertOk();
    }

    // ── Section addressing on multi-report pages ─────────────────────

    public function test_learning_analytics_accepts_a_section(): void
    {
        Livewire::actingAs($this->manager())
            ->withQueryParams(['section' => 'homework'])
            ->test(LearningAnalytics::class)
            ->assertSet('section', 'homework')
            ->assertSee('You were sent to: Homework');
    }

    public function test_an_unknown_section_is_ignored(): void
    {
        $this->actingAs($this->manager());

        $page = new LearningAnalytics;
        $page->section = 'not-a-section';

        $this->assertNull($page->activeSection());
    }

    public function test_dashboard_learning_links_target_specific_sections(): void
    {
        $context = $this->context();

        $this->assertStringContainsString('section=homework', DashboardUrl::learningAnalytics($context, 'homework'));
        $this->assertStringContainsString('section=plans', DashboardUrl::learningAnalytics($context, 'plans'));
    }

    // ── Resource-index destinations ──────────────────────────────────

    public function test_resource_index_urls_use_filaments_own_filter_shape(): void
    {
        $url = DashboardUrl::resourceIndex(OperationalAlertResource::class, ['status' => 'open']);

        // ListRecords binds `#[Url(as: 'filters')] $tableFilters`.
        $this->assertStringContainsString('filters', $url);
        $this->assertStringContainsString('status', $url);
        $this->assertStringContainsString('open', $url);
    }

    public function test_a_filtered_resource_index_opens_with_the_filter_applied(): void
    {
        $manager = $this->manager();
        $manager->givePermissionTo($this->ensurePermission('ViewAny:Lesson'));

        Lesson::factory()->create([
            'status' => LessonStatus::Disputed,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->subDay()->addHour(),
        ]);
        Lesson::factory()->create([
            'status' => LessonStatus::Scheduled,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);

        Livewire::actingAs($manager)
            ->withQueryParams(['filters' => ['status' => ['value' => LessonStatus::Disputed->value]]])
            ->test(ListLessons::class)
            ->assertSet('tableFilters.status.value', LessonStatus::Disputed->value)
            ->assertCanSeeTableRecords(Lesson::query()->where('status', LessonStatus::Disputed)->get())
            ->assertCanNotSeeTableRecords(Lesson::query()->where('status', LessonStatus::Scheduled)->get());
    }

    public function test_instructor_onboarding_drill_down_uses_the_needs_review_tab(): void
    {
        $url = DashboardUrl::resourceIndex(
            InstructorOnboardingResource::class,
            tab: 'needs_review',
        );

        $this->assertStringContainsString('tab=needs_review', $url);
    }

    // ── Every destination actually exists ────────────────────────────

    public function test_every_attention_destination_is_a_real_url(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager);

        // Force every card to render by making each an integrity signal
        // is not possible, so assert on whatever the feed produced plus
        // the always-present integrity cards.
        $items = app(AttentionFeedService::class)->build($manager)->items;

        $this->assertNotSame([], $items);

        foreach ($items as $item) {
            $this->assertStringStartsWith('http', $item->url, "[{$item->key}] must have an absolute URL.");
            $this->assertNotSame('', $item->destinationLabel);
        }
    }

    public function test_every_kpi_and_report_link_is_a_real_url(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager);

        $data = app(DashboardCompositionService::class)->compose($manager, $this->context());

        foreach ($data->kpis as $kpi) {
            if ($kpi->url !== null) {
                $this->assertStringStartsWith('http', $kpi->url, "KPI [{$kpi->key}] URL");
            }
        }

        foreach ([...$data->primaryReports, ...$data->additionalReports] as $report) {
            $this->assertStringStartsWith('http', $report->url, "Report [{$report->key}] URL");
        }

        foreach ($data->charts as $chart) {
            if ($chart->url !== null) {
                $this->assertStringStartsWith('http', $chart->url, "Chart [{$chart->key}] URL");
            }

            foreach ($chart->segments as $segment) {
                $this->assertStringStartsWith('http', (string) $segment['url']);
            }
        }
    }

    // ── Launchpad links must actually open ───────────────────────────

    public function test_launchpad_omits_reports_whose_page_would_reject_the_viewer(): void
    {
        // Regression: the registry's `requiredViewPermission` is not
        // always the gate the destination page enforces.
        // `ReviewsQualityDashboard` guards itself with `ViewQualityDashboard`
        // and `BookingReports` with the Booking viewAny policy, so a
        // registry-"available" report could still 403 on arrival. A
        // browser review of the manager persona caught exactly that.
        $manager = $this->manager();
        $this->actingAs($manager);

        $data = app(DashboardCompositionService::class)
            ->compose($manager, $this->context());

        $keys = array_map(
            fn ($link): string => $link->key,
            [...$data->primaryReports, ...$data->additionalReports],
        );

        $this->assertNotContains('reviews_quality_dashboard', $keys);
        $this->assertNotContains('booking_lesson_kpis', $keys);
        // Reports whose own gate matches the registry still appear.
        $this->assertContains('student_engagement', $keys);
    }

    public function test_every_launchpad_link_opens_for_the_viewer(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager);

        $data = app(DashboardCompositionService::class)
            ->compose($manager, $this->context());

        $links = [...$data->primaryReports, ...$data->additionalReports];

        // Guard the loop: an empty launchpad would make this test pass
        // while proving nothing.
        $this->assertNotSame([], $links, 'The manager persona must have launchpad links to check.');

        foreach ($links as $link) {
            $this->actingAs($manager)
                ->get($link->url)
                ->assertOk("Launchpad link [{$link->key}] must open, not 403.");
        }
    }

    public function test_quality_summary_links_to_the_owning_page_when_moderation_is_gated(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager);

        $summary = collect(app(DashboardCompositionService::class)
            ->compose($manager, $this->context())->summaries)
            ->firstWhere('key', 'quality');

        $this->assertNotNull($summary);

        // Without ViewQualityDashboard the summary must point at the page
        // that actually owns the figures it shows, not the moderation
        // dashboard the viewer cannot open.
        $this->assertStringContainsString('referral-communication-reports', $summary->reportUrl);
        $this->assertStringContainsString('section=quality', $summary->reportUrl);

        $this->actingAs($manager)->get($summary->reportUrl)->assertOk();
    }

    public function test_quality_summary_prefers_the_moderation_dashboard_when_permitted(): void
    {
        $manager = $this->manager();
        $manager->givePermissionTo($this->ensurePermission('ViewQualityDashboard'));
        $this->actingAs($manager);

        $summary = collect(app(DashboardCompositionService::class)
            ->compose($manager, $this->context())->summaries)
            ->firstWhere('key', 'quality');

        $this->assertStringContainsString('reports/reviews-quality', $summary->reportUrl);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function ensurePermission(string $name): string
    {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $name;
    }

    private function context(?ReportingPeriodPreset $preset = null): DashboardContext
    {
        return new DashboardContext(
            period: ReportingPeriod::forPreset($preset ?? ReportingPeriodPreset::Last30Days, ReportingTimezoneResolver::resolve()),
            countryId: null,
        );
    }

    private function manager(): User
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $user->assignRole('manager');

        return $user;
    }
}
