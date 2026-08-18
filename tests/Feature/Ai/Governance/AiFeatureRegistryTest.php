<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Governance;

use App\Ai\Contracts\AiExecutionServiceInterface;
use App\Ai\Contracts\AiFeatureRegistryInterface;
use App\Ai\Contracts\AiTaskInputResolverInterface;
use App\Ai\Contracts\AiTaskResultHandlerInterface;
use App\Ai\DTOs\AiTaskDescriptor;
use App\Ai\DTOs\AiTaskRequest;
use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFailureCode;
use App\Ai\Enums\AiFeature;
use App\Ai\Enums\AiRunStatus;
use App\Ai\Exceptions\AiConfigurationException;
use App\Ai\Jobs\ExecuteAiTaskJob;
use App\Ai\Registry\AiFeatureDefinition;
use App\Ai\Registry\AiFeatureRegistry;
use App\Ai\Services\AiLogger;
use App\Ai\Services\NullTaskInputResolver;
use App\Lessons\Summaries\Resolvers\LessonSummaryResultHandler;
use App\Models\AiRun;
use App\Models\User;
use App\Quality\Intelligence\Resolvers\QualityInsightInputResolver;
use App\Settings\AiSettings;
use App\Settings\FeatureSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The allowlist that decides whether an AI execution may happen at all.
 *
 * The gap this closed: ExecuteAiTaskJob used to resolve whatever
 * class-string its payload named out of the container and call
 * resolve() on it, unchecked — so the boundary deciding which platform
 * data reaches a provider was whatever a caller wrote in a descriptor.
 */
class AiFeatureRegistryTest extends TestCase
{
    use RefreshDatabase;

    /** A real user id: ai_runs.requested_by is a foreign key. */
    private function actorId(): int
    {
        return (int) User::factory()->create()->id;
    }

    private function enableAi(): void
    {
        $features = app(FeatureSettings::class);
        $features->ai_enabled = true;
        $features->save();

        $settings = app(AiSettings::class);
        $settings->provider = 'fake';
        $settings->quality_insights_enabled = true;
        $settings->save();
    }

    private function runJob(AiTaskDescriptor $descriptor): void
    {
        (new ExecuteAiTaskJob($descriptor))->handle(
            app(AiExecutionServiceInterface::class),
            app(AiLogger::class),
            app(AiFeatureRegistryInterface::class),
        );
    }

    // ── Every shipped feature is declared ─────────────────────────────

    public function test_every_shipped_feature_is_registered_by_its_owning_domain(): void
    {
        $registry = app(AiFeatureRegistryInterface::class);

        foreach (AiFeature::cases() as $feature) {
            $this->assertTrue(
                $registry->has($feature),
                "{$feature->value} must be registered before it can run.",
            );
        }
    }

    public function test_every_definition_names_a_real_resolver_and_handler(): void
    {
        foreach (app(AiFeatureRegistryInterface::class)->all() as $definition) {
            $this->assertInstanceOf(
                AiTaskInputResolverInterface::class,
                app($definition->inputResolver),
                "{$definition->feature->value} declares a resolver that is not one.",
            );

            foreach ($definition->resultHandlers as $handler) {
                $this->assertInstanceOf(
                    AiTaskResultHandlerInterface::class,
                    app($handler),
                    "{$definition->feature->value} declares a handler that is not one.",
                );
            }

            $this->assertNotSame('', trim($definition->ownerDomain));
            $this->assertNotSame('', trim($definition->purpose));
            $this->assertNotSame([], $definition->allowedPromptKeys);
        }
    }

    /**
     * The actor rule: only communication safety may run unattended,
     * because a safety check that runs only when asked is not one.
     */
    public function test_only_communication_safety_may_run_without_an_acting_user(): void
    {
        foreach (app(AiFeatureRegistryInterface::class)->all() as $definition) {
            if ($definition->requiresAuthenticatedActor) {
                continue;
            }

            $this->assertContains(
                $definition->feature,
                [AiFeature::CommunicationModeration, AiFeature::PlatformDiagnostics],
                "{$definition->feature->value} must require an acting user.",
            );
        }
    }

    // ── Fail closed ───────────────────────────────────────────────────

    public function test_a_resolver_the_feature_never_declared_is_refused_before_it_is_constructed(): void
    {
        $this->enableAi();

        $this->runJob(new AiTaskDescriptor(
            feature: AiFeature::QualityInsights,
            capability: AiCapability::StructuredGeneration,
            promptKey: 'quality_insight',
            // A resolver belonging to an entirely different feature.
            inputResolver: NullTaskInputResolver::class,
            requestedBy: $this->actorId(),
        ));

        // Nothing ran, nothing was spent, nothing was read.
        $this->assertSame(0, AiRun::query()->count());
    }

    public function test_a_handler_the_feature_never_declared_is_refused(): void
    {
        $this->enableAi();

        $this->runJob(new AiTaskDescriptor(
            feature: AiFeature::QualityInsights,
            capability: AiCapability::StructuredGeneration,
            promptKey: 'quality_insight',
            inputResolver: QualityInsightInputResolver::class,
            // Another feature's handler — a new place its output could
            // land.
            resultHandler: LessonSummaryResultHandler::class,
            requestedBy: $this->actorId(),
        ));

        $this->assertSame(0, AiRun::query()->count());
    }

    /** A feature may not borrow another feature's prompt. */
    public function test_a_prompt_belonging_to_another_feature_is_refused(): void
    {
        $this->enableAi();

        $result = app(AiExecutionServiceInterface::class)->execute(
            new AiTaskRequest(
                feature: AiFeature::QualityInsights,
                capability: AiCapability::StructuredGeneration,
                promptKey: 'lesson_summary',
                requestedBy: $this->actorId(),
            ),
        );

        $this->assertSame(AiRunStatus::Blocked, $result->status);
        $this->assertSame(AiFailureCode::FeatureNotPermitted, $result->failureCode);
    }

    public function test_a_human_facing_feature_dispatched_without_an_actor_is_refused(): void
    {
        $this->enableAi();

        $result = app(AiExecutionServiceInterface::class)->execute(
            new AiTaskRequest(
                feature: AiFeature::QualityInsights,
                capability: AiCapability::StructuredGeneration,
                promptKey: 'quality_insight',
                requestedBy: null,
            ),
        );

        $this->assertSame(AiFailureCode::ActorRequired, $result->failureCode);
    }

    /** Governance denials are recorded, so a silently-dead feature is visible. */
    public function test_a_governance_denial_is_recorded_as_a_blocked_run(): void
    {
        $this->enableAi();

        app(AiExecutionServiceInterface::class)->execute(
            new AiTaskRequest(
                feature: AiFeature::QualityInsights,
                capability: AiCapability::StructuredGeneration,
                promptKey: 'lesson_summary',
                requestedBy: $this->actorId(),
            ),
        );

        $run = AiRun::query()->sole();

        $this->assertSame(AiRunStatus::Blocked, $run->status);
        $this->assertSame(AiFailureCode::FeatureNotPermitted, $run->failure_code);
    }

    public function test_an_unregistered_feature_cannot_run(): void
    {
        $this->enableAi();

        // A registry containing nothing at all.
        app()->instance(AiFeatureRegistryInterface::class, new AiFeatureRegistry);

        $result = app(AiExecutionServiceInterface::class)->execute(
            new AiTaskRequest(
                feature: AiFeature::QualityInsights,
                capability: AiCapability::StructuredGeneration,
                promptKey: 'quality_insight',
                requestedBy: $this->actorId(),
            ),
        );

        $this->assertSame(AiFailureCode::FeatureNotPermitted, $result->failureCode);
    }

    /** Governance denials are never retried — they are defects, not outages. */
    public function test_governance_failures_are_not_retryable(): void
    {
        $this->assertFalse(AiFailureCode::FeatureNotPermitted->isRetryable());
        $this->assertFalse(AiFailureCode::ActorRequired->isRetryable());
        $this->assertTrue(AiFailureCode::FeatureNotPermitted->isPreflight());
    }

    public function test_a_feature_cannot_be_claimed_by_two_domains(): void
    {
        $this->expectException(AiConfigurationException::class);

        app(AiFeatureRegistryInterface::class)->register(new AiFeatureDefinition(
            feature: AiFeature::QualityInsights,
            ownerDomain: 'some/other/domain',
            purpose: 'Attempted takeover.',
            inputResolver: NullTaskInputResolver::class,
            resultHandlers: [],
            allowedPromptKeys: ['anything'],
        ));
    }
}
