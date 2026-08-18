<?php

declare(strict_types=1);

namespace Tests\Feature\Quality\Intelligence;

use App\Ai\Contracts\AiExecutionServiceInterface;
use App\Ai\Contracts\AiPromptRegistryInterface;
use App\Ai\Contracts\AiSchemaRegistryInterface;
use App\Ai\DTOs\AiTaskDescriptor;
use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFailureCode;
use App\Ai\Enums\AiFeature;
use App\Ai\Enums\AiRunStatus;
use App\Ai\Jobs\ExecuteAiTaskJob;
use App\Ai\Services\AiLogger;
use App\Models\AiQualityInsight;
use App\Models\AiRun;
use App\Models\User;
use App\Quality\Intelligence\Contracts\QualityInsightServiceInterface;
use App\Quality\Intelligence\Enums\QualityInsightStatus;
use App\Quality\Intelligence\Exceptions\QualityInsightException;
use App\Quality\Intelligence\Prompts\QualityInsightPrompt;
use App\Quality\Intelligence\Resolvers\QualityInsightInputResolver;
use App\Quality\Intelligence\Resolvers\QualityInsightResultHandler;
use App\Quality\Intelligence\Schemas\QualityInsightSchema;
use App\Settings\AiSettings;
use App\Settings\FeatureSettings;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Feature\Quality\Intelligence\Concerns\BuildsQualityInsightFixtures;
use Tests\TestCase;

/**
 * The generation lifecycle end to end, through the real P0 pipeline:
 * gate → queued job → input resolver → AiExecutionService → provider →
 * schema validation → result handler → stored insight.
 *
 * The queue runs synchronously in the test environment, so dispatching
 * exercises the whole chain rather than just asserting a push.
 */
class QualityInsightGenerationTest extends TestCase
{
    use BuildsQualityInsightFixtures, RefreshDatabase;

    /** Switches the platform onto the real OpenAI adapter with a faked transport. */
    private function useFakedOpenAi(array $payload): void
    {
        $settings = app(AiSettings::class);
        $settings->provider = 'openai';
        $settings->openai_api_key = Crypt::encryptString('sk-test-key');
        $settings->save();

        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($payload, JSON_THROW_ON_ERROR)], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 200],
        ], 200, ['x-request-id' => 'req_p1'])]);
    }

    /** @return array<string, mixed> */
    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'summary' => 'Across the period this instructor completed a small number of lessons with consistently high ratings, though the sample is too small to describe a trend.',
            'strengths' => ['Students repeatedly mention clear explanations.'],
            'concerns' => [],
            'recommended_review' => 'Check again after more lessons are completed.',
            'confidence' => 0.42,
            'requires_human_review' => true,
        ], $overrides);
    }

    /** Runs the queued job as if this were its last permitted attempt. */
    private function runFinalAttempt(AiQualityInsight $insight): void
    {
        $job = new ExecuteAiTaskJob(new AiTaskDescriptor(
            feature: AiFeature::QualityInsights,
            capability: AiCapability::StructuredGeneration,
            promptKey: QualityInsightPrompt::KEY,
            inputResolver: QualityInsightInputResolver::class,
            resultHandler: QualityInsightResultHandler::class,
            correlationId: $insight->getKey(),
            promptVersion: QualityInsightPrompt::VERSION,
        ));

        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn($job->tries);
        $queueJob->shouldReceive('getJobId')->andReturn('job-id');
        $queueJob->shouldReceive('release')->never();

        $job->setJob($queueJob);
        $job->handle(app(AiExecutionServiceInterface::class), app(AiLogger::class));
    }

    private function seedActivity(User $instructor): void
    {
        $this->publishedReview($instructor, $this->student(), 5, 'Explains difficult ideas very clearly and is always prepared.');
        $this->publishedReview($instructor, $this->student('Tom', 'Baker'), 4, 'Good pace, occasionally starts a minute late.');
    }

    // ── Registration ──────────────────────────────────────────────────

    public function test_the_prompt_and_schema_are_registered_with_the_ai_foundation(): void
    {
        $prompts = app(AiPromptRegistryInterface::class);

        $this->assertTrue($prompts->has(QualityInsightPrompt::KEY, QualityInsightPrompt::VERSION));
        $this->assertTrue(app(AiSchemaRegistryInterface::class)->has(QualityInsightSchema::KEY));

        $definition = $prompts->get(QualityInsightPrompt::KEY);

        $this->assertSame(AiFeature::QualityInsights, $definition->feature);
        // Structured, never free-form: the output feeds a stored record.
        $this->assertSame(AiCapability::StructuredGeneration, $definition->capability);
        $this->assertSame(QualityInsightSchema::KEY, $definition->schemaKey);
    }

    // ── Fail closed ───────────────────────────────────────────────────

    public function test_the_capability_flag_off_blocks_generation_entirely(): void
    {
        $features = app(FeatureSettings::class);
        $features->ai_enabled = true;
        $features->save();

        $settings = app(AiSettings::class);
        $settings->provider = 'fake';
        $settings->quality_insights_enabled = false;
        $settings->save();

        $instructor = $this->instructor();

        $this->expectException(QualityInsightException::class);
        $this->expectExceptionMessage('AI quality insights are turned off');

        try {
            app(QualityInsightServiceInterface::class)->request($instructor, $this->period(), $this->admin());
        } finally {
            // Nothing was created and nothing was queued.
            $this->assertSame(0, AiQualityInsight::query()->count());
            $this->assertSame(0, AiRun::query()->count());
        }
    }

    public function test_the_ai_master_switch_off_blocks_generation(): void
    {
        $settings = app(AiSettings::class);
        $settings->quality_insights_enabled = true;
        $settings->save();

        $this->expectException(QualityInsightException::class);

        app(QualityInsightServiceInterface::class)->request($this->instructor(), $this->period(), $this->admin());
    }

    public function test_an_exhausted_budget_blocks_generation_with_an_actionable_message(): void
    {
        $this->enableQualityInsights();

        $settings = app(AiSettings::class);
        $settings->daily_cost_limit = 0.0;
        $settings->save();

        $this->expectException(QualityInsightException::class);
        $this->expectExceptionMessage('spend limit');

        app(QualityInsightServiceInterface::class)->request($this->instructor(), $this->period(), $this->admin());
    }

    public function test_a_non_instructor_can_never_be_analysed(): void
    {
        $this->enableQualityInsights();

        $this->expectException(QualityInsightException::class);
        $this->expectExceptionMessage('only be generated for instructors');

        app(QualityInsightServiceInterface::class)->request($this->student(), $this->period(), $this->admin());
    }

    public function test_a_duplicate_run_for_the_same_instructor_and_period_is_refused(): void
    {
        $this->enableQualityInsights();

        $instructor = $this->instructor();
        $this->seedActivity($instructor);

        // Leaves a Pending insight behind: no provider is configured, so
        // the queued run fails, but the guard is about the row.
        $insight = app(QualityInsightServiceInterface::class)->request($instructor, $this->period(), $this->admin());
        $insight->update(['status' => QualityInsightStatus::Pending]);

        $this->expectException(QualityInsightException::class);
        $this->expectExceptionMessage('already being generated');

        app(QualityInsightServiceInterface::class)->request($instructor, $this->period(), $this->admin());
    }

    // ── Success path ──────────────────────────────────────────────────

    public function test_a_successful_run_stores_validated_output_against_the_insight(): void
    {
        $this->enableQualityInsights();
        $this->useFakedOpenAi($this->validPayload());

        $instructor = $this->instructor();
        $this->seedActivity($instructor);

        $insight = app(QualityInsightServiceInterface::class)
            ->request($instructor, $this->period(), $this->admin())
            ->refresh();

        $this->assertSame(QualityInsightStatus::Ready, $insight->status);
        $this->assertStringContainsString('too small to describe a trend', (string) $insight->summary);
        $this->assertSame(['Students repeatedly mention clear explanations.'], $insight->strengths);
        $this->assertSame([], $insight->concerns);
        $this->assertSame(0.42, $insight->confidence);
        $this->assertTrue($insight->requires_human_review);
        $this->assertSame(QualityInsightPrompt::VERSION, $insight->prompt_version);
    }

    public function test_a_successful_run_is_linked_to_its_ai_run_telemetry(): void
    {
        $this->enableQualityInsights();
        $this->useFakedOpenAi($this->validPayload());

        $instructor = $this->instructor();
        $this->seedActivity($instructor);
        $admin = $this->admin();

        $insight = app(QualityInsightServiceInterface::class)->request($instructor, $this->period(), $admin)->refresh();

        $run = AiRun::query()->findOrFail($insight->ai_run_id);

        $this->assertSame(AiRunStatus::Succeeded, $run->status);
        $this->assertSame(AiFeature::QualityInsights, $run->feature_key);
        $this->assertSame('quality_insight', $run->prompt_key);
        $this->assertSame('v1', $run->prompt_version);
        $this->assertSame(900, $run->input_tokens);
        $this->assertSame(200, $run->output_tokens);
        $this->assertGreaterThan(0, (float) $run->estimated_cost);
        $this->assertSame($admin->id, $run->requested_by);
        // The run is about the instructor; the insight is the record
        // waiting for it.
        $this->assertSame((string) $instructor->id, $run->subject_id);
    }

    /** The model may raise the review requirement; it may never waive it. */
    public function test_a_model_claiming_no_review_is_needed_is_overridden_when_it_raised_concerns(): void
    {
        $this->enableQualityInsights();
        $this->useFakedOpenAi($this->validPayload([
            'concerns' => ['Two students mentioned late starts — worth checking the schedule.'],
            'requires_human_review' => false,
        ]));

        $instructor = $this->instructor();
        $this->seedActivity($instructor);

        $insight = app(QualityInsightServiceInterface::class)->request($instructor, $this->period(), $this->admin())->refresh();

        $this->assertTrue($insight->requires_human_review);
    }

    // ── Failure handling ──────────────────────────────────────────────

    public function test_a_response_failing_the_schema_is_never_stored_and_is_retried(): void
    {
        $this->enableQualityInsights();
        // A summary far below the schema minimum: plausible-looking
        // output that must never reach the insight record.
        $this->useFakedOpenAi($this->validPayload(['summary' => 'Good.']));

        $instructor = $this->instructor();
        $this->seedActivity($instructor);

        $insight = app(QualityInsightServiceInterface::class)->request($instructor, $this->period(), $this->admin())->refresh();

        // Nothing from the rejected response is stored, and the insight
        // stays Pending because generation is non-deterministic — the
        // job releases for another attempt rather than giving up on one
        // malformed answer.
        $this->assertNull($insight->summary);
        $this->assertNull($insight->strengths);
        $this->assertSame(QualityInsightStatus::Pending, $insight->status);

        // The rejected run is still recorded — the tokens were spent.
        $run = AiRun::query()->sole();
        $this->assertSame(AiRunStatus::Rejected, $run->status);
        $this->assertSame(AiFailureCode::SchemaValidationFailed, $run->failure_code);
        $this->assertSame(200, $run->output_tokens);
    }

    public function test_an_insight_is_failed_once_the_retry_budget_is_exhausted(): void
    {
        $this->enableQualityInsights();
        $this->useFakedOpenAi($this->validPayload(['summary' => 'Good.']));

        $instructor = $this->instructor();
        $this->seedActivity($instructor);
        $insight = app(QualityInsightServiceInterface::class)->request($instructor, $this->period(), $this->admin());

        // Drive the final attempt: on the last try the job stops
        // retrying and hands the failure to the domain, so a Pending
        // insight always resolves to something an admin can read.
        $this->runFinalAttempt($insight);

        $insight->refresh();

        $this->assertSame(QualityInsightStatus::Failed, $insight->status);
        $this->assertSame(AiFailureCode::SchemaValidationFailed->value, $insight->failure_code);
        $this->assertNull($insight->summary);
    }

    public function test_a_provider_outage_fails_the_insight_without_losing_it(): void
    {
        $this->enableQualityInsights();

        $settings = app(AiSettings::class);
        $settings->provider = 'openai';
        $settings->openai_api_key = Crypt::encryptString('sk-test-key');
        $settings->save();

        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'bad key']], 401)]);

        $instructor = $this->instructor();
        $this->seedActivity($instructor);

        $insight = app(QualityInsightServiceInterface::class)->request($instructor, $this->period(), $this->admin())->refresh();

        $this->assertSame(QualityInsightStatus::Failed, $insight->status);
        $this->assertSame(AiFailureCode::AuthenticationFailed->value, $insight->failure_code);
        $this->assertNull($insight->summary);
    }

    public function test_an_empty_period_is_refused_before_any_provider_call(): void
    {
        $this->enableQualityInsights();
        Http::fake();

        $settings = app(AiSettings::class);
        $settings->provider = 'openai';
        $settings->openai_api_key = Crypt::encryptString('sk-test-key');
        $settings->save();

        // An instructor with no reviews and no completed lessons.
        $insight = app(QualityInsightServiceInterface::class)
            ->request($this->instructor(), $this->period(), $this->admin())
            ->refresh();

        $this->assertSame(QualityInsightStatus::Failed, $insight->status);
        Http::assertNothingSent();
        $this->assertSame(0, AiRun::query()->count());
    }

    // ── Review workflow ───────────────────────────────────────────────

    public function test_marking_an_insight_reviewed_records_who_and_when(): void
    {
        $this->enableQualityInsights();
        $this->useFakedOpenAi($this->validPayload());

        $instructor = $this->instructor();
        $this->seedActivity($instructor);
        $reviewer = $this->admin();

        $insight = app(QualityInsightServiceInterface::class)->request($instructor, $this->period(), $reviewer)->refresh();

        $reviewed = app(QualityInsightServiceInterface::class)->markReviewed($insight, $reviewer, 'Checked the schedule; nothing to action.');

        $this->assertSame(QualityInsightStatus::Reviewed, $reviewed->status);
        $this->assertSame($reviewer->id, $reviewed->reviewed_by);
        $this->assertNotNull($reviewed->reviewed_at);
        $this->assertSame('Checked the schedule; nothing to action.', $reviewed->review_note);
    }

    public function test_requesting_and_reviewing_are_both_audited(): void
    {
        $this->enableQualityInsights();
        $this->useFakedOpenAi($this->validPayload());

        $instructor = $this->instructor();
        $this->seedActivity($instructor);
        $admin = $this->admin();

        $insight = app(QualityInsightServiceInterface::class)->request($instructor, $this->period(), $admin);
        app(QualityInsightServiceInterface::class)->markReviewed($insight->refresh(), $admin);

        $this->assertDatabaseHas('activity_log', ['log_name' => 'ai_quality_insight', 'event' => 'ai_quality_insight_requested']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'ai_quality_insight', 'event' => 'ai_quality_insight_reviewed']);
    }

    public function test_a_late_duplicate_result_never_overwrites_a_stored_insight(): void
    {
        $this->enableQualityInsights();
        $this->useFakedOpenAi($this->validPayload());

        $instructor = $this->instructor();
        $this->seedActivity($instructor);

        $service = app(QualityInsightServiceInterface::class);
        $insight = $service->request($instructor, $this->period(), $this->admin())->refresh();

        $service->markFailed($insight, AiFailureCode::Timeout->value);

        $this->assertSame(QualityInsightStatus::Ready, $insight->refresh()->status);
    }
}
