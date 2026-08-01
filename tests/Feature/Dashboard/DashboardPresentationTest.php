<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Dashboard\Support\DashboardPalette;
use App\Filament\Pages\Dashboard;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\User;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Presentation behaviour introduced by the visual redesign.
 *
 * Only the parts that carry real logic are covered — colour assignment,
 * empty-state switching, grid balancing, motion gating and accessible
 * text. Pure styling is left to the browser review; asserting on
 * decorative classes would just be a change-detector.
 */
class DashboardPresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
    }

    // ── Domain colour is assigned once and reused ────────────────────

    public function test_a_domain_keeps_one_colour_across_every_surface(): void
    {
        // Students: KPI card, chart and report tile must agree.
        $students = DashboardPalette::domainClass('engaged_students');

        $this->assertSame($students, DashboardPalette::domainClass('student_registrations'));
        $this->assertSame($students, DashboardPalette::domainClass('student_engagement'));

        // Money: summary, chart-less metrics and every finance report.
        $money = DashboardPalette::domainClass('money');

        $this->assertSame($money, DashboardPalette::domainClass('payment_outcomes'));
        $this->assertSame($money, DashboardPalette::domainClass('wallet_activity'));
        $this->assertSame($money, DashboardPalette::domainClass('earnings_settlements'));
    }

    public function test_distinct_domains_do_not_share_a_colour(): void
    {
        $classes = array_map(
            DashboardPalette::domainClass(...),
            ['bookings_scheduled', 'engaged_students', 'money', 'quality', 'marketplace'],
        );

        $this->assertSame($classes, array_unique($classes), 'Each domain must be visually distinguishable.');
    }

    public function test_an_unknown_key_falls_back_to_the_brand_ramp(): void
    {
        $this->assertSame('dash-d-brand', DashboardPalette::domainClass('not-a-real-key'));
        $this->assertSame('dash-d-brand', DashboardPalette::domainClass(null));
    }

    // ── Empty states replace the chart, not sit beside it ────────────

    public function test_an_empty_chart_renders_a_compact_empty_state_and_no_canvas(): void
    {
        $response = $this->actingAs($this->manager())->get(Dashboard::getUrl())->assertOk();

        // With no lessons at all, the outcome chart must degrade to a
        // compact explanation rather than a blank chart-sized canvas.
        $response->assertSee('No lessons were finalized in this period.');
        $response->assertSee('dash-empty', escape: false);
    }

    public function test_a_populated_chart_renders_the_plot_rather_than_an_empty_state(): void
    {
        $this->seedOutcomes();

        $response = $this->actingAs($this->manager())->get(Dashboard::getUrl())->assertOk();

        $response->assertSee('dash-plot', escape: false);
        $response->assertDontSee('No lessons were finalized in this period.');
    }

    public function test_empty_states_state_why_a_figure_is_absent(): void
    {
        $response = $this->actingAs($this->manager())->get(Dashboard::getUrl())->assertOk();

        // The three reasons are never collapsed into one vague message.
        $response->assertSee('No activity in this period');
        $response->assertSee('Not calculable');
    }

    // ── Grid balancing ───────────────────────────────────────────────

    public function test_four_attention_cards_use_a_two_column_grid(): void
    {
        // The seeded manager sees exactly four: two lesson exceptions
        // plus the two healthy integrity confirmations.
        $manager = $this->manager();
        $this->seedDisputes();

        $response = $this->actingAs($manager)->get(Dashboard::getUrl())->assertOk();

        // A three-column grid would strand the fourth card alone.
        $response->assertSee('xl:grid-cols-2', escape: false);
    }

    // ── Motion is gated ──────────────────────────────────────────────

    public function test_only_an_unresolved_critical_item_carries_the_pulse_class(): void
    {
        $manager = $this->manager();
        $this->seedDisputes();

        $html = $this->actingAs($manager)->get(Dashboard::getUrl())->assertOk()->getContent();

        // Healthy zero-count integrity cards must never animate.
        $this->assertStringNotContainsString('dash-attn-healthy dash-pulse', $html);
    }

    // ── Accessible text ──────────────────────────────────────────────

    public function test_severity_is_conveyed_in_words_not_only_colour(): void
    {
        $this->seedDisputes();

        $response = $this->actingAs($this->manager())->get(Dashboard::getUrl())->assertOk();

        $response->assertSee('High');
        $response->assertSee('Healthy');
    }

    public function test_attention_cards_expose_a_descriptive_accessible_name(): void
    {
        $this->seedDisputes();

        $html = $this->actingAs($this->manager())->get(Dashboard::getUrl())->assertOk()->getContent();

        $this->assertStringContainsString('Disputed lessons: 2. High. Opens', $html);
    }

    public function test_section_headings_remain_semantic_and_labelled(): void
    {
        $html = $this->actingAs($this->manager())->get(Dashboard::getUrl())->assertOk()->getContent();

        foreach ([
            'dashboard-hero-heading',
            'dashboard-attention-heading',
            'dashboard-kpi-heading',
            'dashboard-charts-heading',
        ] as $id) {
            $this->assertStringContainsString('aria-labelledby="'.$id.'"', $html);
            $this->assertStringContainsString('id="'.$id.'"', $html);
        }
    }

    // ── The redesign did not weaken the guarantees ───────────────────

    public function test_the_redesigned_dashboard_still_renders_no_tables(): void
    {
        $this->seedOutcomes();

        $html = $this->actingAs($this->manager())->get(Dashboard::getUrl())->assertOk()->getContent();

        $this->assertStringNotContainsString('<table', $html);
    }

    public function test_the_hero_owns_the_actions_so_they_are_not_duplicated(): void
    {
        $html = $this->actingAs($this->manager())->get(Dashboard::getUrl())->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'wire:click="refreshDashboard"'));
        $this->assertSame('', (new Dashboard)->getHeading());
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function seedOutcomes(): void
    {
        Lesson::factory()->count(3)->create([
            'status' => LessonStatus::Completed,
            'outcome' => LessonOutcome::Completed,
            'outcome_finalized_at' => now()->subDay(),
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDays(2)->addHour(),
        ]);
    }

    private function seedDisputes(): void
    {
        Lesson::factory()->count(2)->create([
            'status' => LessonStatus::Disputed,
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->subDays(3)->addHour(),
        ]);
    }

    private function manager(): User
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $user->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));

        return $user;
    }
}
