<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Contracts\AiExecutionServiceInterface;
use App\Ai\Contracts\AiFeatureRegistryInterface;
use App\Ai\Contracts\AiPromptRegistryInterface;
use App\Ai\Contracts\AiProviderInterface;
use App\Ai\Contracts\AiProviderRegistryInterface;
use App\Ai\Contracts\AiSchemaInterface;
use App\Ai\Contracts\AiSchemaRegistryInterface;
use App\Ai\DTOs\AiProviderCapabilities;
use App\Ai\DTOs\AiProviderHealth;
use App\Ai\DTOs\AiTaskRequest;
use App\Ai\DTOs\AiTaskResult;
use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFailureCode;
use App\Ai\Enums\AiFeature;
use App\Ai\Enums\AiModelRole;
use App\Ai\Enums\AiRunStatus;
use App\Ai\Exceptions\AiConfigurationException;
use App\Ai\Prompts\AiPromptCatalog;
use App\Ai\Prompts\PromptDefinition;
use App\Ai\Registry\AiFeatureDefinition;
use App\Ai\Registry\AiFeatureRegistry;
use App\Ai\Registry\AiProviderRegistry;
use App\Ai\Schemas\ConnectivityCheckSchema;
use App\Ai\Schemas\StructuredOutputValidator;
use App\Ai\Services\AiProviderResolver;
use App\Ai\Services\NullTaskInputResolver;
use App\Models\AiRun;
use App\Models\User;
use App\Settings\AiSettings;
use App\Settings\FeatureSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The central execution path: gating, provider/prompt resolution,
 * structured validation, usage recording, and the guarantee that an AI
 * failure is a returned result rather than a thrown exception.
 *
 * Runs against the network-free fake provider throughout — the OpenAI
 * adapter is covered separately in OpenAiProviderTest.
 */
class AiExecutionServiceTest extends TestCase
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

    private function request(AiFeature $feature = AiFeature::PlatformDiagnostics, string $promptKey = 'platform_connectivity_check'): AiTaskRequest
    {
        return new AiTaskRequest(
            feature: $feature,
            capability: AiCapability::StructuredGeneration,
            promptKey: $promptKey,
        );
    }

    private function execute(AiTaskRequest $request): AiTaskResult
    {
        return app(AiExecutionServiceInterface::class)->execute($request);
    }

    /**
     * Permits an ad-hoc prompt under the diagnostics feature. Tests that
     * register their own prompt must also declare it, exactly as a real
     * domain does — the registry is what makes that mandatory.
     */
    private function permitPrompt(string $promptKey): void
    {
        // Rebinds a fresh registry rather than re-registering: the real
        // registry refuses to redefine a feature another domain already
        // owns, which is itself a guard worth keeping (see
        // test_a_feature_cannot_be_redefined_by_a_second_domain).
        $registry = new AiFeatureRegistry;

        $registry->register(new AiFeatureDefinition(
            feature: AiFeature::PlatformDiagnostics,
            ownerDomain: 'tests',
            purpose: 'Test fixture.',
            inputResolver: NullTaskInputResolver::class,
            resultHandlers: [],
            allowedPromptKeys: ['platform_connectivity_check', $promptKey],
            requiresAuthenticatedActor: false,
        ));

        app()->instance(AiFeatureRegistryInterface::class, $registry);
    }

    /** The guard that stops two domains each believing they own a capability. */
    public function test_a_feature_cannot_be_redefined_by_a_second_domain(): void
    {
        $this->expectException(AiConfigurationException::class);
        $this->expectExceptionMessage('already registered by app/Ai');

        app(AiFeatureRegistryInterface::class)->register(new AiFeatureDefinition(
            feature: AiFeature::PlatformDiagnostics,
            ownerDomain: 'some/other/domain',
            purpose: 'Attempted takeover.',
            inputResolver: NullTaskInputResolver::class,
            resultHandlers: [],
            allowedPromptKeys: ['anything_i_like'],
        ));
    }

    // ── Happy path ────────────────────────────────────────────────────

    public function test_a_structured_run_succeeds_and_returns_validated_data(): void
    {
        $this->enableAi();

        $result = $this->execute($this->request());

        $this->assertTrue($result->succeeded());
        $this->assertSame(['ok' => true], $result->data);
    }

    public function test_a_successful_run_records_provider_model_prompt_version_and_usage(): void
    {
        $this->enableAi();

        $result = $this->execute($this->request());

        $run = AiRun::query()->findOrFail($result->runId);

        $this->assertSame(AiRunStatus::Succeeded, $run->status);
        $this->assertSame(AiFeature::PlatformDiagnostics, $run->feature_key);
        $this->assertSame('fake', $run->provider);
        $this->assertSame('platform_connectivity_check', $run->prompt_key);
        $this->assertSame('v1', $run->prompt_version);
        $this->assertSame(1, $run->input_tokens);
        $this->assertSame(1, $run->output_tokens);
        $this->assertNotNull($run->completed_at);
        $this->assertNull($run->failure_code);
    }

    /** The run row is the audit substrate — it must never grow content columns. */
    public function test_a_run_row_stores_no_prompt_or_response_content(): void
    {
        $this->enableAi();

        $result = $this->execute($this->request());
        $stored = AiRun::query()->findOrFail($result->runId)->getAttributes();

        foreach (['prompt', 'response', 'input', 'output', 'content', 'payload', 'variables'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $stored);
        }
    }

    // ── Feature gating ────────────────────────────────────────────────

    public function test_the_master_switch_blocks_execution_before_any_provider_call(): void
    {
        $settings = app(AiSettings::class);
        $settings->provider = 'fake';
        $settings->save();

        $result = $this->execute($this->request());

        $this->assertSame(AiRunStatus::Blocked, $result->status);
        $this->assertSame(AiFailureCode::FeatureDisabled, $result->failureCode);
        $this->assertSame(0, $result->usage->totalTokens());
    }

    public function test_a_capability_flag_blocks_its_feature_while_the_module_is_on(): void
    {
        $this->enableAi();

        // A real registered feature/prompt pair WITH an acting user, so
        // neither the registry nor the actor rule fires and the
        // CAPABILITY FLAG is what refuses.
        $result = $this->execute(new AiTaskRequest(
            feature: AiFeature::LessonSummary,
            capability: AiCapability::StructuredGeneration,
            promptKey: 'lesson_summary',
            requestedBy: User::factory()->create()->id,
        ));

        $this->assertSame(AiRunStatus::Blocked, $result->status);
        $this->assertSame(AiFailureCode::FeatureDisabled, $result->failureCode);
    }

    public function test_a_blocked_run_is_still_recorded_so_silence_is_visible(): void
    {
        $result = $this->execute($this->request());

        $run = AiRun::query()->findOrFail($result->runId);

        $this->assertSame(AiRunStatus::Blocked, $run->status);
        $this->assertSame(AiFailureCode::FeatureDisabled, $run->failure_code);
        $this->assertSame(0.0, (float) $run->estimated_cost);
    }

    public function test_openai_without_a_key_is_treated_as_not_configured(): void
    {
        $features = app(FeatureSettings::class);
        $features->ai_enabled = true;
        $features->save();

        $settings = app(AiSettings::class);
        $settings->provider = 'openai';
        $settings->openai_api_key = null;
        $settings->save();

        $result = $this->execute($this->request());

        $this->assertSame(AiFailureCode::NotConfigured, $result->failureCode);
    }

    // ── Prompt and provider resolution ────────────────────────────────

    /** A prompt the feature never declared is refused by the registry first. */
    public function test_a_prompt_the_feature_never_declared_is_refused(): void
    {
        $this->enableAi();

        $result = $this->execute($this->request(AiFeature::PlatformDiagnostics, 'no_such_prompt_will_ever_exist'));

        $this->assertSame(AiFailureCode::FeatureNotPermitted, $result->failureCode);
    }

    /** A DECLARED prompt that was never registered still fails closed. */
    public function test_a_declared_but_unregistered_prompt_fails_closed(): void
    {
        $this->enableAi();
        $this->permitPrompt('declared_but_never_registered');

        $result = $this->execute($this->request(AiFeature::PlatformDiagnostics, 'declared_but_never_registered'));

        $this->assertSame(AiFailureCode::PromptMissing, $result->failureCode);
    }

    /**
     * AI Release 1 (P0-P4) is complete, so the roadmap list this test
     * once guarded is empty. The invariant that survives is stronger and
     * more useful: the registry contains EXACTLY the prompts the shipped
     * phases register, and nothing else. A fifth prompt appearing —
     * whether a new feature, an experiment, or an accidental
     * registration — fails here rather than reaching production
     * unnoticed.
     */
    public function test_the_prompt_registry_contains_exactly_the_shipped_prompts(): void
    {
        $keys = array_values(array_unique(array_map(
            fn (PromptDefinition $definition): string => $definition->key,
            app(AiPromptRegistryInterface::class)->all(),
        )));

        sort($keys);

        $this->assertSame([
            'communication_risk',            // P4
            'homework_feedback',             // P2
            'lesson_summary',                // P3
            'message_moderation',            // P4
            'platform_connectivity_check',   // P0 infrastructure
            'quality_insight',               // P1
        ], $keys);
    }

    /** Every product prompt is registered by its owning domain, never by the AI module. */
    public function test_every_feature_prompt_is_registered_outside_the_ai_module(): void
    {
        $catalogKeys = array_map(
            fn (PromptDefinition $definition): string => $definition->key,
            AiPromptCatalog::definitions(),
        );

        foreach (['quality_insight', 'homework_feedback', 'lesson_summary', 'communication_risk', 'message_moderation'] as $featurePrompt) {
            $this->assertNotContains($featurePrompt, $catalogKeys, "{$featurePrompt} must be registered by its own domain.");
            $this->assertTrue(app(AiPromptRegistryInterface::class)->has($featurePrompt));
        }
    }

    public function test_the_ai_module_ships_no_feature_prompt_of_its_own(): void
    {
        $catalogKeys = array_map(
            fn (PromptDefinition $definition): string => $definition->key,
            AiPromptCatalog::definitions(),
        );

        // The AI module's own catalogue holds infrastructure prompts
        // only; every product prompt is registered by its owning domain.
        $this->assertSame(['platform_connectivity_check'], $catalogKeys);
    }

    public function test_a_capability_the_provider_does_not_support_fails_closed(): void
    {
        $this->enableAi();

        $this->permitPrompt('test_text_prompt');

        $prompts = app(AiPromptRegistryInterface::class);
        $prompts->register(new PromptDefinition(
            key: 'test_text_prompt',
            version: 'v1',
            feature: AiFeature::PlatformDiagnostics,
            capability: AiCapability::TextGeneration,
            systemTemplate: 'system',
            userTemplate: 'user',
        ));

        // A provider advertising nothing must be refused rather than
        // called and allowed to fatal.
        app()->bind(AiProviderRegistryInterface::class, function () {
            $registry = new AiProviderRegistry;
            $registry->register(new CapabilityLessAiProvider);

            return $registry;
        });
        app()->forgetInstance(AiProviderResolver::class);

        $result = $this->execute(new AiTaskRequest(
            feature: AiFeature::PlatformDiagnostics,
            capability: AiCapability::TextGeneration,
            promptKey: 'test_text_prompt',
        ));

        $this->assertSame(AiFailureCode::CapabilityUnsupported, $result->failureCode);
    }

    // ── Structured output validation ──────────────────────────────────

    public function test_a_response_that_fails_its_schema_is_rejected_and_never_returned(): void
    {
        $this->enableAi();

        // A schema the fake provider cannot satisfy: it emits only the
        // properties the schema declares, so a required property with no
        // declaration can never appear.
        $this->permitPrompt('test_strict_prompt');

        app(AiSchemaRegistryInterface::class)->register(new StrictTestSchema);
        app(AiPromptRegistryInterface::class)->register(new PromptDefinition(
            key: 'test_strict_prompt',
            version: 'v1',
            feature: AiFeature::PlatformDiagnostics,
            capability: AiCapability::StructuredGeneration,
            systemTemplate: 'system',
            userTemplate: 'user',
            schemaKey: StrictTestSchema::KEY,
            modelRole: AiModelRole::Fast,
        ));

        $result = $this->execute(new AiTaskRequest(
            feature: AiFeature::PlatformDiagnostics,
            capability: AiCapability::StructuredGeneration,
            promptKey: 'test_strict_prompt',
        ));

        $this->assertSame(AiRunStatus::Rejected, $result->status);
        $this->assertSame(AiFailureCode::SchemaValidationFailed, $result->failureCode);
        $this->assertNull($result->data);

        // Tokens were still spent — a rejected answer is not a free one.
        $run = AiRun::query()->findOrFail($result->runId);
        $this->assertSame(AiRunStatus::Rejected, $run->status);
        $this->assertSame(1, $run->input_tokens);
    }

    public function test_validation_drops_fields_the_schema_does_not_declare(): void
    {
        $this->enableAi();

        $validated = app(StructuredOutputValidator::class)
            ->validate(['ok' => true, 'hallucinated' => 'ignore me'], new ConnectivityCheckSchema);

        $this->assertSame(['ok' => true], $validated);
    }

    // ── Budget ────────────────────────────────────────────────────────

    public function test_an_exhausted_daily_budget_blocks_execution(): void
    {
        $this->enableAi();

        $settings = app(AiSettings::class);
        $settings->daily_cost_limit = 0.0;
        $settings->save();

        $result = $this->execute($this->request());

        $this->assertSame(AiRunStatus::Blocked, $result->status);
        $this->assertSame(AiFailureCode::BudgetExceeded, $result->failureCode);
    }

    public function test_a_null_budget_means_unlimited(): void
    {
        $this->enableAi();

        $settings = app(AiSettings::class);
        $settings->daily_cost_limit = null;
        $settings->monthly_cost_limit = null;
        $settings->save();

        $this->assertTrue($this->execute($this->request())->succeeded());
    }
}

/** Declares no capabilities at all — used to prove resolution fails closed. */
final class CapabilityLessAiProvider implements AiProviderInterface
{
    public function name(): string
    {
        return 'fake';
    }

    public function capabilities(): AiProviderCapabilities
    {
        return new AiProviderCapabilities;
    }

    public function healthCheck(): AiProviderHealth
    {
        return new AiProviderHealth(healthy: true);
    }
}

/** Requires a property the fake provider will never emit. */
final class StrictTestSchema implements AiSchemaInterface
{
    public const string KEY = 'strict_test_schema';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'strict_test_schema';
    }

    public function jsonSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => ['summary'], 'additionalProperties' => false];
    }

    public function rules(): array
    {
        return ['summary' => ['required', 'string']];
    }
}
