<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Contracts\AiExecutionServiceInterface;
use App\Ai\Contracts\AiTaskInputResolverInterface;
use App\Ai\DTOs\AiTaskDescriptor;
use App\Ai\DTOs\AiTaskRequest;
use App\Ai\DTOs\AiTaskResult;
use App\Ai\DTOs\AiUsage;
use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFailureCode;
use App\Ai\Enums\AiFeature;
use App\Ai\Enums\AiRunStatus;
use App\Ai\Exceptions\AiException;
use App\Ai\Jobs\ExecuteAiTaskJob;
use App\Ai\Services\AiLogger;
use App\Ai\Services\NullTaskInputResolver;
use App\Models\AiRun;
use App\Settings\AiSettings;
use App\Settings\FeatureSettings;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * The reusable AI job: queue isolation, retry classification, and the
 * guarantee that no content ever travels in a queue payload.
 */
class ExecuteAiTaskJobTest extends TestCase
{
    use RefreshDatabase;

    private function descriptor(): AiTaskDescriptor
    {
        return new AiTaskDescriptor(
            feature: AiFeature::PlatformDiagnostics,
            capability: AiCapability::StructuredGeneration,
            promptKey: 'platform_connectivity_check',
            inputResolver: NullTaskInputResolver::class,
        );
    }

    private function enableAi(): void
    {
        $features = app(FeatureSettings::class);
        $features->ai_enabled = true;
        $features->save();

        $settings = app(AiSettings::class);
        $settings->provider = 'fake';
        $settings->save();
    }

    public function test_the_job_is_dispatched_onto_the_dedicated_ai_queue(): void
    {
        Queue::fake();

        ExecuteAiTaskJob::dispatch($this->descriptor());

        Queue::assertPushed(ExecuteAiTaskJob::class, function (ExecuteAiTaskJob $job): bool {
            $this->assertSame('ai', $job->queue);
            $this->assertSame('ai', $job->connection);

            return true;
        });
    }

    /** The queue's retry_after must exceed the job timeout, or two workers can run one generation. */
    public function test_the_ai_queue_retry_window_exceeds_the_job_timeout(): void
    {
        $this->assertGreaterThan(
            (new ExecuteAiTaskJob($this->descriptor()))->timeout,
            (int) config('queue.connections.ai.retry_after'),
        );
    }

    public function test_the_queued_payload_carries_identifiers_only_and_no_content(): void
    {
        $descriptor = new AiTaskDescriptor(
            feature: AiFeature::LessonSummary,
            capability: AiCapability::StructuredGeneration,
            promptKey: 'lesson_summary',
            inputResolver: NullTaskInputResolver::class,
            subjectType: 'lesson',
            subjectId: '42',
        );

        $payload = serialize(new ExecuteAiTaskJob($descriptor));

        foreach (['variables', 'systemTemplate', 'userTemplate', 'content', 'rendered'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload);
        }

        $this->assertStringContainsString('lesson_summary', $payload);
    }

    public function test_the_descriptor_dto_has_no_property_that_could_hold_content(): void
    {
        $properties = array_map(
            fn (\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass(AiTaskDescriptor::class))->getProperties(),
        );

        $this->assertNotContains('variables', $properties);
        $this->assertContains('inputResolver', $properties);
    }

    public function test_a_successful_run_completes_the_job_without_retrying(): void
    {
        $this->enableAi();

        (new ExecuteAiTaskJob($this->descriptor()))->handle(
            app(AiExecutionServiceInterface::class),
            app(AiLogger::class),
        );

        $this->assertSame(AiRunStatus::Succeeded, AiRun::query()->sole()->status);
    }

    public function test_a_transient_failure_releases_the_job_for_another_attempt(): void
    {
        $this->runWithQueueJob(AiFailureCode::RateLimited, attempts: 1, expectedRelease: 30);
    }

    public function test_a_permanent_failure_is_never_retried(): void
    {
        $this->runWithQueueJob(AiFailureCode::AuthenticationFailed, attempts: 1, expectedRelease: null);
    }

    public function test_the_retry_budget_stops_a_transient_failure_from_looping_forever(): void
    {
        $this->runWithQueueJob(AiFailureCode::Timeout, attempts: 3, expectedRelease: null);
    }

    /**
     * Drives the real job (it is final, so no partial mock) against a
     * faked queue job — which is what attempts()/release() actually talk
     * to — so the retry decision is exercised exactly as it runs on a
     * worker.
     */
    private function runWithQueueJob(AiFailureCode $code, int $attempts, ?int $expectedRelease): void
    {
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempts);
        $queueJob->shouldReceive('getJobId')->andReturn('job-id');

        if ($expectedRelease === null) {
            $queueJob->shouldReceive('release')->never();
        } else {
            $queueJob->shouldReceive('release')->once()->with($expectedRelease);
        }

        $job = new ExecuteAiTaskJob($this->descriptor());
        $job->setJob($queueJob);
        $job->handle($this->executionReturning($code), app(AiLogger::class));
    }

    public function test_an_unresolvable_subject_ends_the_job_without_calling_a_provider(): void
    {
        $this->enableAi();

        app()->bind(FailingInputResolver::class, fn (): FailingInputResolver => new FailingInputResolver);

        (new ExecuteAiTaskJob(new AiTaskDescriptor(
            feature: AiFeature::PlatformDiagnostics,
            capability: AiCapability::StructuredGeneration,
            promptKey: 'platform_connectivity_check',
            inputResolver: FailingInputResolver::class,
        )))->handle(app(AiExecutionServiceInterface::class), app(AiLogger::class));

        $this->assertSame(0, AiRun::query()->count());
    }

    private function executionReturning(AiFailureCode $code): AiExecutionServiceInterface
    {
        return new class($code) implements AiExecutionServiceInterface
        {
            public function __construct(private readonly AiFailureCode $code) {}

            public function execute(AiTaskRequest $request): AiTaskResult
            {
                return new AiTaskResult(
                    runId: 'run-id',
                    status: AiRunStatus::Failed,
                    usage: AiUsage::none(),
                    failureCode: $this->code,
                );
            }
        };
    }
}

/** Stands in for a subject that was deleted between dispatch and execution. */
final class FailingInputResolver implements AiTaskInputResolverInterface
{
    public function resolve(AiTaskDescriptor $descriptor): array
    {
        throw new AiException('Subject no longer exists.');
    }
}
