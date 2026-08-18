<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Evaluation;

use App\Ai\Enums\AiFeature;
use App\Ai\Enums\AiRunStatus;
use App\Ai\Evaluation\Contracts\AiFeedbackRecorderInterface;
use App\Ai\Evaluation\Enums\AiFeedbackAction;
use App\Ai\Evaluation\Enums\AiFeedbackReason;
use App\Models\AiRun;
use App\Models\HomeworkAssignment;
use App\Models\User;
use App\Reporting\Contracts\AiEvaluationReportServiceInterface;
use App\Reporting\DTOs\Ai\AiFeatureEvaluationRow;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\ValueObjects\ReportingPeriod;
use App\Settings\AiSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The evaluation read model: aggregation accuracy, the derived-outcome
 * design, and the guarantee that it reads without writing.
 */
class AiEvaluationReportTest extends TestCase
{
    use RefreshDatabase;

    private function period(): ReportingPeriod
    {
        return ReportingPeriod::forPreset(ReportingPeriodPreset::Last30Days);
    }

    private function operator(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'Configure:AiPlatform', 'guard_name' => 'web']));

        return $user->fresh();
    }

    private function aiRun(AiFeature $feature, array $overrides = []): AiRun
    {
        return AiRun::query()->create(array_replace([
            'feature_key' => $feature->value,
            'provider' => 'openai',
            'model' => 'gpt-4.1',
            'prompt_key' => 'quality_insight',
            'prompt_version' => 'v1',
            'status' => AiRunStatus::Succeeded->value,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'estimated_cost' => 0.01,
            'latency_ms' => 1000,
        ], $overrides));
    }

    /** Feature rows are keyed by feature; this finds one without assuming order. */
    private function featureRow(array $rows, AiFeature $feature): ?AiFeatureEvaluationRow
    {
        foreach ($rows as $row) {
            if ($row->featureKey === $feature->value) {
                return $row;
            }
        }

        return null;
    }

    /** Insight rows written directly — the report reads status columns, not the domain service. */
    private function insight(string $status, string $promptVersion = 'v1', ?string $runId = null): void
    {
        DB::table('ai_quality_insights')->insert([
            'id' => (string) Str::uuid(),
            'instructor_id' => User::factory()->create()->id,
            'ai_run_id' => $runId,
            'period_preset' => 'last_30_days',
            'period_start' => now()->subDays(30)->toDateString(),
            'period_end' => now()->toDateString(),
            'period_timezone' => 'UTC',
            'period_label' => 'Last 30 days',
            'status' => $status,
            'prompt_key' => 'quality_insight',
            'prompt_version' => $promptVersion,
            'requires_human_review' => true,
            'requested_by' => User::factory()->create()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── Aggregation ───────────────────────────────────────────────────

    public function test_run_counters_are_aggregated_per_feature_and_status(): void
    {
        $this->aiRun(AiFeature::QualityInsights);
        $this->aiRun(AiFeature::QualityInsights, ['status' => AiRunStatus::Failed->value, 'estimated_cost' => 0.0]);
        $this->aiRun(AiFeature::LessonSummary, ['prompt_key' => 'lesson_summary']);

        $overview = app(AiEvaluationReportServiceInterface::class)->overview($this->operator(), $this->period());

        $insights = $this->featureRow($overview->features, AiFeature::QualityInsights);

        $this->assertSame(2, $insights->runs);
        $this->assertSame(1, $insights->succeeded);
        $this->assertSame(1, $insights->failed);
        $this->assertSame(0.01, $insights->estimatedCost);
        $this->assertSame(300, $insights->totalTokens());
        // 0.01 (succeeded insight) + 0.00 (failed) + 0.01 (summary).
        $this->assertSame(0.02, round($overview->totalCost, 2));
    }

    /**
     * Outcomes come from the feature's own table, not from a duplicated
     * evaluation column that could drift.
     */
    public function test_outcomes_are_read_from_the_features_own_table(): void
    {
        $this->insight('reviewed');
        $this->insight('reviewed');
        $this->insight('ready');

        $overview = app(AiEvaluationReportServiceInterface::class)->overview($this->operator(), $this->period());
        $row = $this->featureRow($overview->features, AiFeature::QualityInsights);

        $this->assertSame(2, $row->acceptedOutcomes);
        $this->assertSame(1, $row->awaitingHuman);
        $this->assertSame('Reviewed', $row->acceptedLabel);
    }

    public function test_acceptance_and_cost_per_accepted_outcome_are_calculated(): void
    {
        $this->aiRun(AiFeature::HomeworkAssistant, ['prompt_key' => 'homework_feedback', 'estimated_cost' => 0.05]);
        $this->aiRun(AiFeature::HomeworkAssistant, ['prompt_key' => 'homework_feedback', 'estimated_cost' => 0.05]);

        foreach (['used', 'used', 'used', 'discarded'] as $status) {
            DB::table('homework_ai_feedback_drafts')->insert([
                'id' => (string) Str::uuid(),
                'homework_assignment_id' => HomeworkAssignment::factory()->create()->id,
                'requested_by' => User::factory()->create()->id,
                'status' => $status,
                'prompt_key' => 'homework_feedback',
                'prompt_version' => 'v1',
                'requires_instructor_review' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $overview = app(AiEvaluationReportServiceInterface::class)->overview($this->operator(), $this->period());
        $row = $this->featureRow($overview->features, AiFeature::HomeworkAssistant);

        $this->assertSame(0.75, $row->acceptanceRate());
        // 0.10 total spend / 3 accepted.
        $this->assertSame(0.033333, $row->costPerAcceptedOutcome());
    }

    /** "Nobody has looked" must never render as "nobody found it useful". */
    public function test_rates_are_null_rather_than_zero_when_nothing_was_decided(): void
    {
        $this->insight('ready');

        $overview = app(AiEvaluationReportServiceInterface::class)->overview($this->operator(), $this->period());
        $row = $this->featureRow($overview->features, AiFeature::QualityInsights);

        $this->assertNull($row->acceptanceRate());
        $this->assertNull($row->helpfulRate());
        $this->assertNull($row->costPerAcceptedOutcome());
    }

    public function test_verdicts_and_their_reasons_are_aggregated(): void
    {
        $recorder = app(AiFeedbackRecorderInterface::class);

        $recorder->record($this->aiRun(AiFeature::QualityInsights)->getKey(), AiFeedbackAction::Helpful, actorId: User::factory()->create()->id);
        $recorder->record($this->aiRun(AiFeature::QualityInsights)->getKey(), AiFeedbackAction::NotHelpful, AiFeedbackReason::TooGeneric, User::factory()->create()->id);
        $recorder->record($this->aiRun(AiFeature::QualityInsights)->getKey(), AiFeedbackAction::NotHelpful, AiFeedbackReason::TooGeneric, User::factory()->create()->id);

        $overview = app(AiEvaluationReportServiceInterface::class)->overview($this->operator(), $this->period());
        $row = $this->featureRow($overview->features, AiFeature::QualityInsights);

        $this->assertSame(1, $row->helpfulVerdicts);
        $this->assertSame(2, $row->notHelpfulVerdicts);
        $this->assertSame(['too_generic' => 2], $row->notHelpfulReasons);
        $this->assertSame(0.3333, $row->helpfulRate());
    }

    public function test_median_latency_is_used_rather_than_a_mean(): void
    {
        foreach ([100, 200, 300, 40000] as $latency) {
            $this->aiRun(AiFeature::QualityInsights, ['latency_ms' => $latency]);
        }

        $overview = app(AiEvaluationReportServiceInterface::class)->overview($this->operator(), $this->period());

        // Median of 100/200/300/40000 is 250; the mean would be 10150.
        $this->assertSame(250, $this->featureRow($overview->features, AiFeature::QualityInsights)->medianLatencyMs);
    }

    public function test_a_feature_with_no_activity_is_omitted_rather_than_shown_as_zeros(): void
    {
        $this->aiRun(AiFeature::QualityInsights);

        $overview = app(AiEvaluationReportServiceInterface::class)->overview($this->operator(), $this->period());

        $this->assertNotNull($this->featureRow($overview->features, AiFeature::QualityInsights));
        $this->assertNull($this->featureRow($overview->features, AiFeature::LessonSummary));
    }

    public function test_activity_outside_the_period_is_excluded(): void
    {
        $old = $this->aiRun(AiFeature::QualityInsights);
        $old->forceFill(['created_at' => now()->subMonths(6)])->save();

        $overview = app(AiEvaluationReportServiceInterface::class)->overview($this->operator(), $this->period());

        $this->assertSame([], $overview->features);
    }

    // ── Prompt version comparison ─────────────────────────────────────

    public function test_prompt_versions_are_compared_with_their_own_outcomes(): void
    {
        $this->aiRun(AiFeature::QualityInsights, ['prompt_version' => 'v1']);
        $this->aiRun(AiFeature::QualityInsights, ['prompt_version' => 'v2']);

        $this->insight('reviewed', 'v1');
        $this->insight('reviewed', 'v2');
        $this->insight('reviewed', 'v2');

        $overview = app(AiEvaluationReportServiceInterface::class)->overview($this->operator(), $this->period());

        $versions = collect($overview->promptVersions)->keyBy('promptVersion');

        $this->assertSame(1, $versions['v1']->acceptedOutcomes);
        $this->assertSame(2, $versions['v2']->acceptedOutcomes);
        // Neither has enough runs to act on — the guard against tuning
        // a prompt because of noise.
        $this->assertFalse($versions['v1']->hasEnoughEvidence());
    }

    // ── Budget position ───────────────────────────────────────────────

    public function test_the_budget_position_reflects_configured_limits(): void
    {
        $settings = app(AiSettings::class);
        $settings->daily_cost_limit = 10.0;
        $settings->monthly_cost_limit = null;
        $settings->save();

        $this->aiRun(AiFeature::QualityInsights, ['estimated_cost' => 2.5]);

        $overview = app(AiEvaluationReportServiceInterface::class)->overview($this->operator(), $this->period());

        $this->assertSame(0.25, $overview->dailyBudgetUsedRatio());
        // Null limit means unlimited, never "no headroom".
        $this->assertNull($overview->monthlyBudgetUsedRatio());
    }

    // ── Authorization and read-only ───────────────────────────────────

    public function test_an_operator_with_the_ai_platform_permission_may_view(): void
    {
        $this->assertTrue(app(AiEvaluationReportServiceInterface::class)->canView($this->operator()));
    }

    public function test_a_user_without_the_permission_is_denied(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));

        $this->assertFalse(app(AiEvaluationReportServiceInterface::class)->canView($user->fresh()));

        $this->expectException(AuthorizationException::class);

        app(AiEvaluationReportServiceInterface::class)->overview($user->fresh(), $this->period());
    }

    public function test_a_guest_is_denied(): void
    {
        $this->assertFalse(app(AiEvaluationReportServiceInterface::class)->canView(null));
    }

    /** Reporting must never mutate what it measures. */
    public function test_generating_the_report_writes_nothing(): void
    {
        $this->aiRun(AiFeature::QualityInsights);
        $this->insight('reviewed');

        $before = [
            'runs' => DB::table('ai_runs')->count(),
            'insights' => DB::table('ai_quality_insights')->count(),
            'feedback' => DB::table('ai_feedback_events')->count(),
        ];

        app(AiEvaluationReportServiceInterface::class)->overview($this->operator(), $this->period());

        $this->assertSame($before['runs'], DB::table('ai_runs')->count());
        $this->assertSame($before['insights'], DB::table('ai_quality_insights')->count());
        $this->assertSame($before['feedback'], DB::table('ai_feedback_events')->count());
    }
}
