<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Contracts\AiExecutionServiceInterface;
use App\Ai\Contracts\AiRunRepositoryInterface;
use App\Ai\DTOs\AiTaskRequest;
use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFeature;
use App\Ai\Enums\AiRunStatus;
use App\Ai\Services\AiBudgetGuard;
use App\Ai\Services\AiCostEstimator;
use App\Models\AiRun;
use App\Models\User;
use App\Settings\AiSettings;
use App\Settings\FeatureSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Cost and usage accounting: token capture, price estimation, per-
 * feature attribution, and the spend figures the budget guard and the
 * settings page read.
 */
class AiUsageTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function enableAi(): void
    {
        $features = app(FeatureSettings::class);
        $features->ai_enabled = true;
        $features->save();

        $settings = app(AiSettings::class);
        $settings->provider = 'fake';
        $settings->save();
    }

    public function test_a_run_records_tokens_latency_and_the_requesting_user(): void
    {
        $this->enableAi();
        $user = User::factory()->create();

        $result = app(AiExecutionServiceInterface::class)->execute(new AiTaskRequest(
            feature: AiFeature::PlatformDiagnostics,
            capability: AiCapability::StructuredGeneration,
            promptKey: 'platform_connectivity_check',
            requestedBy: $user->id,
            subjectType: 'user',
            subjectId: (string) $user->id,
        ));

        $run = AiRun::query()->findOrFail($result->runId);

        $this->assertSame($user->id, $run->requested_by);
        $this->assertSame('user', $run->subject_type);
        $this->assertSame(2, $run->totalTokens());
        $this->assertNotNull($run->latency_ms);
        $this->assertSame('fake-request', $run->provider_request_id);
    }

    public function test_cost_is_estimated_from_the_configured_price_table(): void
    {
        $settings = app(AiSettings::class);
        $settings->model_pricing = ['gpt-4.1' => '2.0/8.0'];
        $settings->save();

        $estimator = app(AiCostEstimator::class);

        // 1M input at 2.0 + 0.5M output at 8.0
        $this->assertSame(6.0, $estimator->estimate('gpt-4.1', 1_000_000, 500_000));
        $this->assertTrue($estimator->isPriced('gpt-4.1'));
    }

    public function test_an_unpriced_model_estimates_zero_and_is_reported_as_unpriced(): void
    {
        $settings = app(AiSettings::class);
        $settings->model_pricing = [];
        $settings->save();

        $estimator = app(AiCostEstimator::class);

        $this->assertSame(0.0, $estimator->estimate('gpt-4.1', 1_000_000, 1_000_000));
        $this->assertFalse($estimator->isPriced('gpt-4.1'));
    }

    public function test_a_failed_run_is_recorded_with_its_failure_code(): void
    {
        // Module off — a blocked run still produces a row.
        $result = app(AiExecutionServiceInterface::class)->execute(new AiTaskRequest(
            feature: AiFeature::PlatformDiagnostics,
            capability: AiCapability::StructuredGeneration,
            promptKey: 'platform_connectivity_check',
        ));

        $run = AiRun::query()->findOrFail($result->runId);

        $this->assertSame(AiRunStatus::Blocked, $run->status);
        $this->assertNotNull($run->failure_code);
        $this->assertNotNull($run->completed_at);
    }

    public function test_spend_is_aggregated_per_day_month_and_feature(): void
    {
        AiRun::query()->create([
            'feature_key' => AiFeature::QualityInsights->value,
            'provider' => 'openai',
            'status' => AiRunStatus::Succeeded->value,
            'input_tokens' => 1000,
            'output_tokens' => 500,
            'estimated_cost' => 1.5,
        ]);

        AiRun::query()->create([
            'feature_key' => AiFeature::LessonSummary->value,
            'provider' => 'openai',
            'status' => AiRunStatus::Succeeded->value,
            'estimated_cost' => 2.25,
        ]);

        $repository = app(AiRunRepositoryInterface::class);

        $this->assertSame(3.75, $repository->estimatedCostSince(Carbon::now()->startOfDay()));
        $this->assertSame(1.5, $repository->estimatedCostSince(Carbon::now()->startOfMonth(), AiFeature::QualityInsights));
        $this->assertSame(2, $repository->countSince(Carbon::now()->startOfDay()));

        $byFeature = $repository->usageByFeatureSince(Carbon::now()->startOfMonth());

        $this->assertSame(1000, $byFeature['quality_insights']['input_tokens']);
        $this->assertSame(1, $byFeature['lesson_summary']['runs']);
    }

    public function test_the_budget_guard_reads_the_same_aggregate(): void
    {
        AiRun::query()->create([
            'feature_key' => AiFeature::QualityInsights->value,
            'provider' => 'openai',
            'status' => AiRunStatus::Succeeded->value,
            'estimated_cost' => 4.0,
        ]);

        $this->assertSame(4.0, app(AiBudgetGuard::class)->spentToday());
        $this->assertSame(4.0, app(AiBudgetGuard::class)->spentThisMonth());
    }

    public function test_yesterdays_spend_does_not_count_against_todays_budget(): void
    {
        $run = AiRun::query()->create([
            'feature_key' => AiFeature::QualityInsights->value,
            'provider' => 'openai',
            'status' => AiRunStatus::Succeeded->value,
            'estimated_cost' => 9.0,
        ]);

        $run->forceFill(['created_at' => Carbon::now()->subDays(2)])->save();

        $this->assertSame(0.0, app(AiBudgetGuard::class)->spentToday());
    }
}
