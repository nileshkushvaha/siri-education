<?php

declare(strict_types=1);

namespace Tests\Feature\Homework\Copilot;

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
use App\Homework\Contracts\HomeworkServiceInterface;
use App\Homework\Copilot\Contracts\HomeworkFeedbackDraftServiceInterface;
use App\Homework\Copilot\Enums\HomeworkFeedbackDraftStatus;
use App\Homework\Copilot\Exceptions\HomeworkCopilotException;
use App\Homework\Copilot\Prompts\HomeworkFeedbackPrompt;
use App\Homework\Copilot\Resolvers\HomeworkCopilotInputResolver;
use App\Homework\Copilot\Resolvers\HomeworkFeedbackResultHandler;
use App\Homework\Copilot\Schemas\HomeworkFeedbackSchema;
use App\Homework\Enums\HomeworkStatus;
use App\Models\AiRun;
use App\Models\HomeworkAiFeedbackDraft;
use App\Settings\AiSettings;
use App\Settings\FeatureSettings;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Feature\Homework\Copilot\Concerns\BuildsCopilotFixtures;
use Tests\TestCase;

/**
 * The draft lifecycle end to end through the real P0 pipeline, and the
 * guarantee that a draft never becomes published feedback or a grade on
 * its own.
 */
class HomeworkCopilotGenerationTest extends TestCase
{
    use BuildsCopilotFixtures, RefreshDatabase;

    // ── Registration ──────────────────────────────────────────────────

    public function test_the_prompt_and_schema_are_registered_with_the_ai_foundation(): void
    {
        $prompts = app(AiPromptRegistryInterface::class);

        $this->assertTrue($prompts->has(HomeworkFeedbackPrompt::KEY, HomeworkFeedbackPrompt::VERSION));
        $this->assertTrue(app(AiSchemaRegistryInterface::class)->has(HomeworkFeedbackSchema::KEY));

        $definition = $prompts->get(HomeworkFeedbackPrompt::KEY);

        $this->assertSame(AiFeature::HomeworkAssistant, $definition->feature);
        $this->assertSame(AiCapability::StructuredGeneration, $definition->capability);
        $this->assertSame(HomeworkFeedbackSchema::KEY, $definition->schemaKey);
    }

    /** No grade can be returned because the contract has nowhere to put one. */
    public function test_the_schema_admits_no_grade_score_or_pass_field(): void
    {
        $schema = new HomeworkFeedbackSchema;

        $properties = array_keys($schema->jsonSchema()['properties']);

        foreach (['score', 'grade', 'mark', 'percentage', 'pass', 'correct', 'rank'] as $forbidden) {
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
        $settings->homework_assistant_enabled = false;
        $settings->save();

        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student());

        $this->expectException(HomeworkCopilotException::class);
        $this->expectExceptionMessage('turned off');

        try {
            app(HomeworkFeedbackDraftServiceInterface::class)->request($assignment, $instructor);
        } finally {
            $this->assertSame(0, HomeworkAiFeedbackDraft::query()->count());
            $this->assertSame(0, AiRun::query()->count());
        }
    }

    public function test_the_ai_master_switch_off_blocks_generation(): void
    {
        $settings = app(AiSettings::class);
        $settings->homework_assistant_enabled = true;
        $settings->save();

        $instructor = $this->instructor();

        $this->expectException(HomeworkCopilotException::class);

        app(HomeworkFeedbackDraftServiceInterface::class)
            ->request($this->submittedAssignment($instructor, $this->student()), $instructor);
    }

    public function test_an_exhausted_budget_blocks_generation(): void
    {
        $this->enableHomeworkCopilot();

        $settings = app(AiSettings::class);
        $settings->daily_cost_limit = 0.0;
        $settings->save();

        $instructor = $this->instructor();

        $this->expectException(HomeworkCopilotException::class);
        $this->expectExceptionMessage('usage limit');

        app(HomeworkFeedbackDraftServiceInterface::class)
            ->request($this->submittedAssignment($instructor, $this->student()), $instructor);
    }

    public function test_unsubmitted_homework_can_never_be_sent(): void
    {
        $this->enableHomeworkCopilot();

        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student(), overrides: [
            'status' => HomeworkStatus::Pending,
            'submission_text' => null,
            'submitted_at' => null,
        ]);

        $this->expectException(HomeworkCopilotException::class);
        $this->expectExceptionMessage('once the student has submitted');

        app(HomeworkFeedbackDraftServiceInterface::class)->request($assignment, $instructor);
    }

    public function test_already_reviewed_homework_can_never_be_sent(): void
    {
        $this->enableHomeworkCopilot();

        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student(), overrides: [
            'status' => HomeworkStatus::Graded,
        ]);

        $this->expectException(HomeworkCopilotException::class);
        $this->expectExceptionMessage('already been reviewed');

        app(HomeworkFeedbackDraftServiceInterface::class)->request($assignment, $instructor);
    }

    public function test_a_second_request_while_one_is_in_flight_is_refused(): void
    {
        $this->enableHomeworkCopilot();

        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student());

        $service = app(HomeworkFeedbackDraftServiceInterface::class);
        $draft = $service->request($assignment, $instructor);
        $draft->update(['status' => HomeworkFeedbackDraftStatus::Pending]);

        $this->expectException(HomeworkCopilotException::class);
        $this->expectExceptionMessage('already being generated');

        $service->request($assignment->refresh(), $instructor);
    }

    public function test_an_empty_submission_is_refused_before_any_provider_call(): void
    {
        $this->enableHomeworkCopilot();
        $this->useFakedOpenAi($this->validDraftPayload());
        Http::fake(['api.openai.com/*' => Http::response([], 200)]);

        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student(), 'ok');

        $draft = app(HomeworkFeedbackDraftServiceInterface::class)->request($assignment, $instructor)->refresh();

        $this->assertSame(HomeworkFeedbackDraftStatus::Failed, $draft->status);
        $this->assertSame(0, AiRun::query()->count());
    }

    // ── Success path ──────────────────────────────────────────────────

    public function test_a_successful_run_stores_a_validated_draft(): void
    {
        $this->enableHomeworkCopilot();
        $this->useFakedOpenAi($this->validDraftPayload());

        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student());

        $draft = app(HomeworkFeedbackDraftServiceInterface::class)->request($assignment, $instructor)->refresh();

        $this->assertSame(HomeworkFeedbackDraftStatus::Ready, $draft->status);
        $this->assertStringContainsString('showed their working', (string) $draft->summary);
        $this->assertSame(['Clear working shown for the first two parts.'], $draft->strengths);
        $this->assertStringContainsString('Nice work', (string) $draft->suggested_feedback);
        $this->assertSame(0.66, $draft->confidence);
        $this->assertTrue($draft->requires_instructor_review);
        $this->assertSame(HomeworkFeedbackPrompt::VERSION, $draft->prompt_version);
    }

    public function test_a_successful_run_is_linked_to_its_ai_run_telemetry(): void
    {
        $this->enableHomeworkCopilot();
        $this->useFakedOpenAi($this->validDraftPayload());

        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student());

        $draft = app(HomeworkFeedbackDraftServiceInterface::class)->request($assignment, $instructor)->refresh();
        $run = AiRun::query()->findOrFail($draft->ai_run_id);

        $this->assertSame(AiRunStatus::Succeeded, $run->status);
        $this->assertSame(AiFeature::HomeworkAssistant, $run->feature_key);
        $this->assertSame('homework_feedback', $run->prompt_key);
        $this->assertSame(1200, $run->input_tokens);
        $this->assertSame(350, $run->output_tokens);
        $this->assertGreaterThan(0, (float) $run->estimated_cost);
        $this->assertSame($instructor->id, $run->requested_by);
    }

    /** The model does not get a vote on whether a human reviews its draft. */
    public function test_the_review_requirement_cannot_be_waived_by_the_model(): void
    {
        $this->enableHomeworkCopilot();
        $this->useFakedOpenAi($this->validDraftPayload(['requires_instructor_review' => false]));

        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student());

        $draft = app(HomeworkFeedbackDraftServiceInterface::class)->request($assignment, $instructor)->refresh();

        $this->assertTrue($draft->requires_instructor_review);
    }

    // ── The draft never becomes the outcome ───────────────────────────

    public function test_a_ready_draft_publishes_nothing_and_grades_nothing(): void
    {
        $this->enableHomeworkCopilot();
        $this->useFakedOpenAi($this->validDraftPayload());

        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student());

        app(HomeworkFeedbackDraftServiceInterface::class)->request($assignment, $instructor);

        $assignment->refresh();

        $this->assertNull($assignment->feedback);
        $this->assertNull($assignment->grade);
        $this->assertSame(HomeworkStatus::Submitted, $assignment->status);
    }

    public function test_using_a_draft_records_provenance_without_publishing_it(): void
    {
        $this->enableHomeworkCopilot();
        $this->useFakedOpenAi($this->validDraftPayload());

        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student());

        $service = app(HomeworkFeedbackDraftServiceInterface::class);
        $draft = $service->request($assignment, $instructor)->refresh();

        $used = $service->markUsed($draft, $instructor);

        $this->assertSame(HomeworkFeedbackDraftStatus::Used, $used->status);
        $this->assertNotNull($used->used_at);
        // Still nothing published.
        $this->assertNull($assignment->refresh()->feedback);
    }

    /** Published feedback is what the instructor typed, whatever the draft said. */
    public function test_published_feedback_is_the_instructors_own_text(): void
    {
        $this->enableHomeworkCopilot();
        $this->useFakedOpenAi($this->validDraftPayload());

        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student());

        $service = app(HomeworkFeedbackDraftServiceInterface::class);
        $draft = $service->request($assignment, $instructor)->refresh();
        $service->markUsed($draft, $instructor);

        app(HomeworkServiceInterface::class)
            ->review($assignment, 'I rewrote this entirely in my own words.', 'A');

        $assignment->refresh();

        $this->assertSame('I rewrote this entirely in my own words.', $assignment->feedback);
        $this->assertNotSame($draft->suggested_feedback, $assignment->feedback);
        // The grade came from the instructor; no AI path can write it.
        $this->assertSame('A', $assignment->grade);
    }

    public function test_discarding_a_draft_leaves_the_homework_untouched(): void
    {
        $this->enableHomeworkCopilot();
        $this->useFakedOpenAi($this->validDraftPayload());

        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student());

        $service = app(HomeworkFeedbackDraftServiceInterface::class);
        $draft = $service->request($assignment, $instructor)->refresh();

        $discarded = $service->discard($draft, $instructor);

        $this->assertSame(HomeworkFeedbackDraftStatus::Discarded, $discarded->status);
        $this->assertSame(HomeworkStatus::Submitted, $assignment->refresh()->status);
    }

    // ── Failure handling ──────────────────────────────────────────────

    public function test_a_provider_outage_fails_the_draft_without_losing_it(): void
    {
        $this->enableHomeworkCopilot();

        $settings = app(AiSettings::class);
        $settings->provider = 'openai';
        $settings->openai_api_key = Crypt::encryptString('sk-test-key');
        $settings->save();

        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'bad key']], 401)]);

        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student());

        $draft = app(HomeworkFeedbackDraftServiceInterface::class)->request($assignment, $instructor)->refresh();

        $this->assertSame(HomeworkFeedbackDraftStatus::Failed, $draft->status);
        $this->assertSame(AiFailureCode::AuthenticationFailed->value, $draft->failure_code);
        $this->assertNull($draft->suggested_feedback);
    }

    public function test_a_response_failing_the_schema_is_never_stored(): void
    {
        $this->enableHomeworkCopilot();
        // Too short for the schema minimum — plausible-looking output
        // that must not reach an instructor.
        $this->useFakedOpenAi($this->validDraftPayload(['suggested_feedback' => 'Good.']));

        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student());

        $draft = app(HomeworkFeedbackDraftServiceInterface::class)->request($assignment, $instructor)->refresh();

        $this->assertNull($draft->suggested_feedback);
        // Retryable: generation is non-deterministic, so the job releases
        // rather than giving up on one malformed answer.
        $this->assertSame(HomeworkFeedbackDraftStatus::Pending, $draft->status);
        $this->assertSame(AiRunStatus::Rejected, AiRun::query()->sole()->status);
    }

    public function test_a_draft_is_failed_once_the_retry_budget_is_exhausted(): void
    {
        $this->enableHomeworkCopilot();
        $this->useFakedOpenAi($this->validDraftPayload(['suggested_feedback' => 'Good.']));

        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student());

        $draft = app(HomeworkFeedbackDraftServiceInterface::class)->request($assignment, $instructor);

        $job = new ExecuteAiTaskJob(new AiTaskDescriptor(
            feature: AiFeature::HomeworkAssistant,
            capability: AiCapability::StructuredGeneration,
            promptKey: HomeworkFeedbackPrompt::KEY,
            inputResolver: HomeworkCopilotInputResolver::class,
            resultHandler: HomeworkFeedbackResultHandler::class,
            correlationId: $draft->getKey(),
            promptVersion: HomeworkFeedbackPrompt::VERSION,
        ));

        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn($job->tries);
        $queueJob->shouldReceive('getJobId')->andReturn('job-id');
        $queueJob->shouldReceive('release')->never();

        $job->setJob($queueJob);
        $job->handle(app(AiExecutionServiceInterface::class), app(AiLogger::class));

        $draft->refresh();

        $this->assertSame(HomeworkFeedbackDraftStatus::Failed, $draft->status);
        $this->assertSame(AiFailureCode::SchemaValidationFailed->value, $draft->failure_code);
    }

    public function test_requesting_a_draft_is_audited(): void
    {
        $this->enableHomeworkCopilot();
        $this->useFakedOpenAi($this->validDraftPayload());

        $instructor = $this->instructor();
        $assignment = $this->submittedAssignment($instructor, $this->student());

        app(HomeworkFeedbackDraftServiceInterface::class)->request($assignment, $instructor);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'homework_ai_copilot',
            'event' => 'homework_ai_draft_requested',
        ]);
    }
}
