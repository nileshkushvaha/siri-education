<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Dashboard\DTOs\DashboardContext;
use App\Dashboard\DTOs\DashboardData;
use App\Dashboard\DTOs\DomainSummary;
use App\Dashboard\DTOs\KpiCard;
use App\Dashboard\Enums\AttentionSeverity;
use App\Dashboard\Services\AttentionFeedService;
use App\Dashboard\Services\DashboardCompositionService;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Enums\LessonStatus;
use App\Models\Currency;
use App\Models\Lesson;
use App\Models\User;
use App\Models\Wallet;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Support\ReportingTimezoneResolver;
use App\Reporting\ValueObjects\ReportingPeriod;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Metric correctness.
 *
 * These are the distinctions the reporting layer's `MetricRegistry`
 * insists on, verified at the dashboard boundary so a display-layer
 * shortcut can never quietly redefine an authoritative metric.
 */
class DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
    }

    // ── Finalized outcome, never lesson status ───────────────────────

    public function test_completed_lessons_count_uses_the_finalized_outcome_not_the_status(): void
    {
        $manager = $this->manager();

        // A lesson marked Completed by STATUS but never finalized. The
        // registry is explicit that this must not count.
        Lesson::factory()->create([
            'status' => LessonStatus::Completed,
            'outcome' => LessonOutcome::Pending,
            'outcome_finalized_at' => null,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDays(2)->addHour(),
        ]);

        $this->assertSame('0', $this->kpi($manager, 'lessons_completed')->value);

        // Now one with a genuinely finalized Completed outcome.
        Lesson::factory()->create([
            'status' => LessonStatus::Completed,
            'outcome' => LessonOutcome::Completed,
            'outcome_finalized_at' => now()->subDay(),
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDays(2)->addHour(),
        ]);

        $this->flushDashboardCache();

        $this->assertSame('1', $this->kpi($manager, 'lessons_completed')->value);
    }

    public function test_lessons_completed_definition_names_the_outcome_column(): void
    {
        $definition = $this->kpi($this->manager(), 'lessons_completed')->definition;

        $this->assertStringContainsString('finalized outcome', $definition);
        $this->assertStringContainsString('LessonStatus::Completed', $definition);
    }

    // ── Engagement is not account status ─────────────────────────────

    public function test_engaged_students_is_not_derived_from_account_status(): void
    {
        $manager = $this->manager();

        // Three student accounts exist and are "active" by account status,
        // but none has any booking or finalized lesson in the period.
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        User::factory()->count(3)->create(['status' => 'active'])
            ->each(fn (User $u) => $u->assignRole('student'));

        $card = $this->kpi($manager, 'engaged_students');

        $this->assertSame('0', $card->value, 'Account status must not make a student "engaged".');
        $this->assertStringContainsString('Not an account status', $card->definition);
    }

    public function test_engaged_students_card_is_not_labelled_active_students(): void
    {
        $this->assertSame('Engaged students', $this->kpi($this->manager(), 'engaged_students')->label);
    }

    // ── Null denominators render an em dash, never 0% ────────────────

    public function test_demo_to_paid_conversion_renders_an_em_dash_with_no_demo_bookers(): void
    {
        $card = $this->kpi($this->manager(), 'demo_to_paid_conversion');

        $this->assertSame('—', $card->value);
        $this->assertNotSame('0%', $card->value);
        $this->assertNotSame('0.0%', $card->value);
        $this->assertTrue($card->isUnavailable);
        $this->assertNotNull($card->unavailableReason);
    }

    public function test_null_summary_metrics_render_an_em_dash(): void
    {
        $data = $this->compose($this->manager());

        foreach ($data->summaries as $summary) {
            foreach ($summary->metrics as $metric) {
                if ($metric->isUnavailable) {
                    $this->assertSame('—', $metric->value);
                }
            }
        }

        // With an empty database at least one nullable metric must have
        // surfaced, otherwise this test proves nothing.
        $unavailable = collect($data->summaries)
            ->flatMap(fn ($s) => $s->metrics)
            ->filter(fn ($m) => $m->isUnavailable);

        $this->assertGreaterThan(0, $unavailable->count());
    }

    // ── Currencies are never combined ────────────────────────────────

    public function test_wallet_liability_is_reported_per_currency_and_never_summed(): void
    {
        $manager = $this->manager();

        $inr = Currency::query()->firstOrCreate(
            ['code' => 'INR'],
            ['name' => 'Indian Rupee', 'symbol' => '₹', 'minor_units' => 2, 'status' => 'active'],
        );
        $usd = Currency::query()->firstOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$', 'minor_units' => 2, 'status' => 'active'],
        );

        Wallet::factory()->create([
            'currency_id' => $inr->id, 'currency_code' => 'INR',
            'balance_minor' => 100_00, 'available_balance_minor' => 100_00,
        ]);
        Wallet::factory()->create([
            'currency_id' => $usd->id, 'currency_code' => 'USD',
            'balance_minor' => 50_00, 'available_balance_minor' => 50_00,
        ]);

        $this->flushDashboardCache();

        $money = $this->summary($this->compose($manager), 'money');
        $liability = collect($money->metrics)->firstWhere('label', 'Wallet liability');

        $this->assertNotNull($liability);
        // Both currencies appear, side by side. A single combined 150.00
        // would be the failure this guards against.
        $this->assertStringContainsString('INR', $liability->value);
        $this->assertStringContainsString('USD', $liability->value);
        $this->assertStringNotContainsString('150', $liability->value);
    }

    public function test_no_dashboard_figure_is_labelled_revenue(): void
    {
        $data = $this->compose($this->manager());

        $labels = collect($data->kpis)->map(fn (KpiCard $c): string => $c->label)
            ->merge(collect($data->summaries)->flatMap(fn ($s) => collect($s->metrics)->pluck('label')))
            ->map(fn (string $l): string => strtolower($l));

        foreach ($labels as $label) {
            $this->assertStringNotContainsString('revenue', $label);
            $this->assertStringNotContainsString('margin', $label);
            $this->assertStringNotContainsString('retention', $label);
            $this->assertStringNotContainsString('utilization', $label);
        }
    }

    // ── Current state is not period-scoped ───────────────────────────

    public function test_attention_counts_do_not_change_with_the_dashboard_period(): void
    {
        $manager = $this->manager();

        Lesson::factory()->create([
            'status' => LessonStatus::Disputed,
            'starts_at' => now()->subMonths(8),
            'ends_at' => now()->subMonths(8)->addHour(),
        ]);

        // The attention feed takes no period at all — the same feed is
        // produced regardless of what the dashboard selector says.
        $this->actingAs($manager);
        $feed = app(AttentionFeedService::class)->build($manager);

        $disputed = collect($feed->items)->firstWhere('key', 'disputed_lessons');

        $this->assertNotNull($disputed);
        // A lesson eight months old still counts: current state, not period.
        $this->assertSame(1, $disputed->count);
        $this->assertSame('As of now', $disputed->asOfLabel);
    }

    public function test_active_instructors_kpi_is_marked_as_current_state(): void
    {
        $this->assertSame('As of now', $this->kpi($this->manager(), 'active_instructors')->contextLabel);
    }

    public function test_period_scoped_kpis_carry_the_period_label(): void
    {
        $this->assertSame('Last 30 days', $this->kpi($this->manager(), 'bookings_scheduled')->contextLabel);
    }

    // ── Meeting problems are counted exactly once ────────────────────

    public function test_meeting_problems_appear_only_as_operational_alerts(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager);

        $keys = collect(app(AttentionFeedService::class)->build($manager)->items)->pluck('key');

        // Operational alerts are the authoritative source. There must be
        // no second, Operations-report-derived meeting card alongside it.
        $this->assertNotContains('meeting_creation_failures', $keys);
        $this->assertNotContains('meetings_missing', $keys);
        $this->assertNotContains('missing_meeting_link', $keys);
    }

    public function test_operational_alert_cards_partition_the_open_alert_set(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager);

        $service = app(AttentionFeedService::class);

        // Both cards are integrity-free counters over disjoint type sets,
        // so with no alerts at all neither renders.
        $keys = collect($service->build($manager)->items)->pluck('key');

        $this->assertNotContains('lesson_access_alerts', $keys);
        $this->assertNotContains('operational_alerts', $keys);
    }

    // ── Integrity signals render at zero ─────────────────────────────

    public function test_wallet_ledger_integrity_renders_a_healthy_card_at_zero(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager);

        $item = collect(app(AttentionFeedService::class)->build($manager)->items)
            ->firstWhere('key', 'wallet_ledger_mismatch');

        $this->assertNotNull($item, 'A zero integrity signal must still render.');
        $this->assertSame(0, $item->count);
        $this->assertSame(AttentionSeverity::Success, $item->effectiveSeverity());
    }

    public function test_ordinary_zero_count_cards_are_hidden(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager);

        $keys = collect(app(AttentionFeedService::class)->build($manager)->items)->pluck('key');

        // Nothing disputed, nothing overdue — those cards must not render.
        $this->assertNotContains('disputed_lessons', $keys);
        $this->assertNotContains('homework_overdue', $keys);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function kpi(User $user, string $key): KpiCard
    {
        foreach ($this->compose($user)->kpis as $card) {
            if ($card->key === $key) {
                return $card;
            }
        }

        $this->fail("KPI [{$key}] was not composed for this user.");
    }

    private function summary(DashboardData $data, string $key): DomainSummary
    {
        foreach ($data->summaries as $summary) {
            if ($summary->key === $key) {
                return $summary;
            }
        }

        $this->fail("Summary [{$key}] was not composed for this user.");
    }

    private function compose(User $user): DashboardData
    {
        $this->actingAs($user);

        return app(DashboardCompositionService::class)->compose($user, $this->context());
    }

    private function flushDashboardCache(): void
    {
        cache()->flush();
    }

    private function context(): DashboardContext
    {
        return new DashboardContext(
            period: ReportingPeriod::forPreset(ReportingPeriodPreset::Last30Days, ReportingTimezoneResolver::resolve()),
            countryId: null,
        );
    }

    private function manager(): User
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $user->assignRole('manager');

        return $user;
    }
}
