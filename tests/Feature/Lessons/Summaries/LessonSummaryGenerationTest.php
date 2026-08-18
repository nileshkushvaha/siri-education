<?php

declare(strict_types=1);

namespace Tests\Feature\Lessons\Summaries;

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
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Enums\LessonStatus;
use App\Lessons\Summaries\Contracts\LessonSummaryServiceInterface;
use App\Lessons\Summaries\Enums\LessonSummaryStatus;
use App\Lessons\Summaries\Exceptions\LessonSummaryException;
use App\Lessons\Summaries\Prompts\LessonSummaryPrompt;
use App\Lessons\Summaries\Resolvers\LessonSummaryInputResolver;
use App\Lessons\Summaries\Resolvers\LessonSummaryResultHandler;
use App\Lessons\Summaries\Schemas\LessonSummarySchema;
use App\Models\AiRun;
use App\Models\LessonAiSummary;
use App\Settings\AiSettings;
use App\Settings\FeatureSettings;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Feature\Lessons\Summaries\Concerns\BuildsLessonSummaryFixtures;
use Tests\TestCase;

/**
 * The summary lifecycle end to end through the real P0 pipeline, and
 * the guarantee that a draft never becomes a lesson record, a progress
 * signal, or anything else on its own.
 */
class LessonSummaryGenerationTest extends TestCase
{
    use BuildsLessonSummaryFixtures, RefreshDatabase;

    // ── Registration ──────────────────────────────────────────────────

    public function test_the_prompt_and_schema_are_registered_with_the_ai_foundation(): void
    {
        $prompts = app(AiPromptRegistryInterface::class);

        $this->assertTrue($prompts->has(LessonSummaryPrompt::KEY, LessonSummaryPrompt::VERSION));
        $this->assertTrue(app(AiSchemaRegistryInterface::class)->has(LessonSummarySchema::KEY));

        $definition = $prompts->get(LessonSummaryPrompt::KEY);

        $this->assertSame(AiFeature::LessonSummary, $definition->feature);
        $this->assertSame(AiCapability::StructuredGeneration, $definition->capability);
        $this->assertSame(LessonSummarySchema::KEY, $definition->schemaKey);
    }

    /** No progress metric can be returned because the contract has nowhere to put one. */
    public function test_the_schema_admits_no_mastery_progress_or_grade_field(): void
    {
        $schema = new LessonSummarySchema;

        $properties = array_keys($schema->jsonSchema()['properties']);

        foreach (['mastery', 'progress', 'level', 'grade', 'score', 'percent', 'rank'] as $forbidden) {
            foreach ($properties as $property) {
                $this->assertStringNotContainsString($forbidden, $property);
            }
        }

        $this->assertFalse($schema->jsonSchema()['additionalProperties']);
    }

    // ── Fail closed ───────────────────────────────────────────────────

    public function test_the_capability_flag_off_blocks_generation(): void
    {
        $features = app(FeatureSettings::class);
        $features->ai_enabled = true;
        $features->save();

        $settings = app(AiSettings::class);
        $settings->provider = 'fake';
        $settings->lesson_summary_enabled = false;
        $settings->save();

        $instructor = $this->instructor();
        $lesson = $this->completedLesson($instructor, $this->student());

        $this->expectException(LessonSummaryException::class);
        $this->expectExceptionMessage('turned off');

        try {
            app(LessonSummaryServiceInterface::class)->request($lesson, $instructor);
        } finally {
            $this->assertSame(0, LessonAiSummary::query()->count());
            $this->assertSame(0, AiRun::query()->count());
        }
    }

    public function test_the_ai_master_switch_off_blocks_generation(): void
    {
        $settings = app(AiSettings::class);
        $settings->lesson_summary_enabled = true;
        $settings->save();

        $instructor = $this->instructor();

        $this->expectException(LessonSummaryException::class);

        app(LessonSummaryServiceInterface::class)
            ->request($this->completedLesson($instructor, $this->student()), $instructor);
    }

    public function test_an_exhausted_budget_blocks_generation(): void
    {
        $this->enableLessonSummaries();

        $settings = app(AiSettings::class);
        $settings->daily_cost_limit = 0.0;
        $settings->save();

        $instructor = $this->instructor();

        $this->expectException(LessonSummaryException::class);
        $this->expectExceptionMessage('usage limit');

        app(LessonSummaryServiceInterface::class)
            ->request($this->completedLesson($instructor, $this->student()), $instructor);
    }

    /** A lesson whose delivery is still contested is not settled enough to document. */
    public function test_a_lesson_without_a_completed_outcome_is_refused(): void
    {
        $this->enableLessonSummaries();

        $instructor = $this->instructor();
        $lesson = $this->completedLesson($instructor, $this->student(), overrides: [
            'status' => LessonStatus::Disputed,
            'outcome' => LessonOutcome::TechnicalIssue,
        ]);

        $this->expectException(LessonSummaryException::class);
        $this->expectExceptionMessage('only available once the lesson is completed');

        app(LessonSummaryServiceInterface::class)->request($lesson, $instructor);
    }

    public function test_a_second_request_while_one_is_in_flight_is_refused(): void
    {
        $this->enableLessonSummaries();

        $instructor = $this->instructor();
        $lesson = $this->completedLesson($instructor, $this->student());

        $service = app(LessonSummaryServiceInterface::class);
        $summary = $service->request($lesson, $instructor);
        $summary->update(['status' => LessonSummaryStatus::Pending]);

        $this->expectException(LessonSummaryException::class);
        $this->expectExceptionMessage('already being generated');

        $service->request($lesson->refresh(), $instructor);
    }

    public function test_an_approved_summary_cannot_be_regenerated(): void
    {
        $this->enableLessonSummaries();
        $this->useFakedOpenAi($this->validSummaryPayload());

        $instructor = $this->instructor();
        $lesson = $this->completedLesson($instructor, $this->student());

        $service = app(LessonSummaryServiceInterface::class);
        $summary = $service->request($lesson, $instructor)->refresh();
        $service->approve($summary, $instructor, 'My own approved write-up of this lesson.');

        $this->expectException(LessonSummaryException::class);
        $this->expectExceptionMessage('already has an approved summary');

        $service->request($lesson->refresh(), $instructor);
    }

    public function test_a_lesson_with_nothing_recorded_is_refused_before_any_provider_call(): void
    {
        $this->enableLessonSummaries();
        Http::fake();

        $settings = app(AiSettings::class);
        $settings->provider = 'openai';
        $settings->openai_api_key = Crypt::encryptString('sk-test-key');
        $settings->save();

        $instructor = $this->instructor();
        // No completion note, no topic, no homework.
        $lesson = $this->completedLesson($instructor, $this->student(), null);

        $summary = app(LessonSummaryServiceInterface::class)->request($lesson, $instructor)->refresh();

        $this->assertSame(LessonSummaryStatus::Failed, $summary->status);
        Http::assertNothingSent();
        $this->assertSame(0, AiRun::query()->count());
    }

    // ── Success path ──────────────────────────────────────────────────

    public function test_a_successful_run_stores_a_validated_draft(): void
    {
        $this->enableLessonSummaries();
        $this->useFakedOpenAi($this->validSummaryPayload());

        $instructor = $this->instructor();
        $lesson = $this->completedLesson($instructor, $this->student());

        $summary = app(LessonSummaryServiceInterface::class)->request($lesson, $instructor)->refresh();

        $this->assertSame(LessonSummaryStatus::Ready, $summary->status);
        $this->assertStringContainsString('quadratic equations', (string) $summary->lesson_summary);
        $this->assertSame(['Factorising quadratic expressions'], $summary->topics_covered);
        $this->assertSame(['Solving quadratics using the formula.'], $summary->next_focus);
        $this->assertSame(0.55, $summary->confidence);
        $this->assertTrue($summary->requires_instructor_review);
        $this->assertSame(LessonSummaryPrompt::VERSION, $summary->prompt_version);
        // Not yet a record of anything.
        $this->assertNull($summary->approved_summary);
    }

    public function test_a_successful_run_is_linked_to_its_ai_run_telemetry(): void
    {
        $this->enableLessonSummaries();
        $this->useFakedOpenAi($this->validSummaryPayload());

        $instructor = $this->instructor();
        $lesson = $this->completedLesson($instructor, $this->student());

        $summary = app(LessonSummaryServiceInterface::class)->request($lesson, $instructor)->refresh();
        $run = AiRun::query()->findOrFail($summary->ai_run_id);

        $this->assertSame(AiRunStatus::Succeeded, $run->status);
        $this->assertSame(AiFeature::LessonSummary, $run->feature_key);
        $this->assertSame('lesson_summary', $run->prompt_key);
        $this->assertSame(700, $run->input_tokens);
        $this->assertGreaterThan(0, (float) $run->estimated_cost);
        $this->assertSame($instructor->id, $run->requested_by);
    }

    public function test_the_review_requirement_cannot_be_waived_by_the_model(): void
    {
        $this->enableLessonSummaries();
        $this->useFakedOpenAi($this->validSummaryPayload(['requires_instructor_review' => false]));

        $instructor = $this->instructor();
        $lesson = $this->completedLesson($instructor, $this->student());

        $summary = app(LessonSummaryServiceInterface::class)->request($lesson, $instructor)->refresh();

        $this->assertTrue($summary->requires_instructor_review);
    }

    // ── The draft never becomes the record ────────────────────────────

    public function test_a_ready_draft_changes_nothing_about_the_lesson_or_the_plan(): void
    {
        $this->enableLessonSummaries();
        $this->useFakedOpenAi($this->validSummaryPayload());

        $instructor = $this->instructor();
        $student = $this->student();
        $plan = $this->activePlanFor($instructor, $student);
        $lesson = $this->completedLesson($instructor, $student, overrides: ['learning_plan_id' => $plan->id]);

        $originalNotes = $lesson->completion_notes;
        $originalProgress = $plan->progress_percent;

        app(LessonSummaryServiceInterface::class)->request($lesson, $instructor);

        $lesson->refresh();
        $plan->refresh();

        $this->assertSame($originalNotes, $lesson->completion_notes);
        $this->assertSame(LessonStatus::Completed, $lesson->status);
        $this->assertSame(LessonOutcome::Completed, $lesson->outcome);
        $this->assertSame($originalProgress, $plan->progress_percent);
    }

    /** What is recorded is the instructor's text, whatever the draft said. */
    public function test_approval_records_the_instructors_own_text_beside_the_draft(): void
    {
        $this->enableLessonSummaries();
        $this->useFakedOpenAi($this->validSummaryPayload());

        $instructor = $this->instructor();
        $lesson = $this->completedLesson($instructor, $this->student());

        $service = app(LessonSummaryServiceInterface::class);
        $summary = $service->request($lesson, $instructor)->refresh();

        $approved = $service->approve($summary, $instructor, 'I rewrote this entirely in my own words for the record.');

        $this->assertSame(LessonSummaryStatus::Approved, $approved->status);
        $this->assertSame('I rewrote this entirely in my own words for the record.', $approved->approved_summary);
        $this->assertSame($instructor->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
        // The draft is retained beside it, so the two stay distinguishable.
        $this->assertNotSame($approved->approved_summary, $approved->lesson_summary);
        $this->assertNotNull($approved->lesson_summary);
    }

    public function test_discarding_leaves_the_lesson_untouched_and_allows_a_retry(): void
    {
        $this->enableLessonSummaries();
        $this->useFakedOpenAi($this->validSummaryPayload());

        $instructor = $this->instructor();
        $lesson = $this->completedLesson($instructor, $this->student());

        $service = app(LessonSummaryServiceInterface::class);
        $summary = $service->request($lesson, $instructor)->refresh();

        $discarded = $service->discard($summary, $instructor);
        $this->assertSame(LessonSummaryStatus::Discarded, $discarded->status);

        // Regenerating reuses the same row rather than leaving competing
        // accounts of one lesson behind.
        $regenerated = $service->request($lesson->refresh(), $instructor);

        $this->assertSame($summary->getKey(), $regenerated->getKey());
        $this->assertSame(1, LessonAiSummary::query()->count());
    }

    // ── Failure handling ──────────────────────────────────────────────

    public function test_a_provider_outage_fails_the_summary_without_losing_it(): void
    {
        $this->enableLessonSummaries();

        $settings = app(AiSettings::class);
        $settings->provider = 'openai';
        $settings->openai_api_key = Crypt::encryptString('sk-test-key');
        $settings->save();

        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'bad key']], 401)]);

        $instructor = $this->instructor();
        $lesson = $this->completedLesson($instructor, $this->student());

        $summary = app(LessonSummaryServiceInterface::class)->request($lesson, $instructor)->refresh();

        $this->assertSame(LessonSummaryStatus::Failed, $summary->status);
        $this->assertSame(AiFailureCode::AuthenticationFailed->value, $summary->failure_code);
        $this->assertNull($summary->lesson_summary);
    }

    public function test_a_response_failing_the_schema_is_never_stored(): void
    {
        $this->enableLessonSummaries();
        $this->useFakedOpenAi($this->validSummaryPayload(['lesson_summary' => 'Good.']));

        $instructor = $this->instructor();
        $lesson = $this->completedLesson($instructor, $this->student());

        $summary = app(LessonSummaryServiceInterface::class)->request($lesson, $instructor)->refresh();

        $this->assertNull($summary->lesson_summary);
        $this->assertSame(LessonSummaryStatus::Pending, $summary->status);
        $this->assertSame(AiRunStatus::Rejected, AiRun::query()->sole()->status);
    }

    public function test_a_summary_is_failed_once_the_retry_budget_is_exhausted(): void
    {
        $this->enableLessonSummaries();
        $this->useFakedOpenAi($this->validSummaryPayload(['lesson_summary' => 'Good.']));

        $instructor = $this->instructor();
        $lesson = $this->completedLesson($instructor, $this->student());
        $summary = app(LessonSummaryServiceInterface::class)->request($lesson, $instructor);

        $job = new ExecuteAiTaskJob(new AiTaskDescriptor(
            feature: AiFeature::LessonSummary,
            capability: AiCapability::StructuredGeneration,
            promptKey: LessonSummaryPrompt::KEY,
            inputResolver: LessonSummaryInputResolver::class,
            resultHandler: LessonSummaryResultHandler::class,
            correlationId: $summary->getKey(),
            promptVersion: LessonSummaryPrompt::VERSION,
        ));

        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn($job->tries);
        $queueJob->shouldReceive('getJobId')->andReturn('job-id');
        $queueJob->shouldReceive('release')->never();

        $job->setJob($queueJob);
        $job->handle(app(AiExecutionServiceInterface::class), app(AiLogger::class));

        $summary->refresh();

        $this->assertSame(LessonSummaryStatus::Failed, $summary->status);
        $this->assertSame(AiFailureCode::SchemaValidationFailed->value, $summary->failure_code);
    }

    public function test_requesting_and_approving_are_both_audited(): void
    {
        $this->enableLessonSummaries();
        $this->useFakedOpenAi($this->validSummaryPayload());

        $instructor = $this->instructor();
        $lesson = $this->completedLesson($instructor, $this->student());

        $service = app(LessonSummaryServiceInterface::class);
        $summary = $service->request($lesson, $instructor)->refresh();
        $service->approve($summary, $instructor, 'My own approved write-up of this lesson.');

        $this->assertDatabaseHas('activity_log', ['log_name' => 'lesson_ai_summary', 'event' => 'lesson_ai_summary_requested']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'lesson_ai_summary', 'event' => 'lesson_ai_summary_approved']);
    }
}
