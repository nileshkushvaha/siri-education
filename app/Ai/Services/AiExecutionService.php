<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\Contracts\AiExecutionServiceInterface;
use App\Ai\Contracts\AiPromptRegistryInterface;
use App\Ai\Contracts\AiSchemaRegistryInterface;
use App\Ai\Contracts\EmbeddingProviderInterface;
use App\Ai\Contracts\ModerationProviderInterface;
use App\Ai\Contracts\StructuredGenerationProviderInterface;
use App\Ai\Contracts\TextGenerationProviderInterface;
use App\Ai\DTOs\AiEmbeddingRequest;
use App\Ai\DTOs\AiEmbeddingResult;
use App\Ai\DTOs\AiModerationRequest;
use App\Ai\DTOs\AiModerationResult;
use App\Ai\DTOs\AiStructuredRequest;
use App\Ai\DTOs\AiTaskRequest;
use App\Ai\DTOs\AiTaskResult;
use App\Ai\DTOs\AiTextRequest;
use App\Ai\DTOs\AiUsage;
use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFailureCode;
use App\Ai\Enums\AiRunStatus;
use App\Ai\Exceptions\AiConfigurationException;
use App\Ai\Exceptions\AiException;
use App\Ai\Exceptions\AiSchemaValidationException;
use App\Ai\Prompts\RenderedPrompt;
use App\Ai\Schemas\StructuredOutputValidator;
use App\Models\AiRun;
use App\Settings\AiSettings;
use Throwable;

/**
 * The central AI execution path — the ONLY way business code reaches a
 * provider.
 *
 * Every control lives inside one method so none of them can be skipped
 * by a caller:
 *
 *   feature gate → prompt resolution → provider resolution →
 *   model resolution → budget → run row → provider call →
 *   schema validation → usage/cost recording → safe log
 *
 * It does not throw for provider or validation failures. An AI feature
 * being unavailable is a normal operating condition — the platform must
 * keep working without it — so callers receive a failed AiTaskResult
 * and make an explicit decision, rather than an exception deciding for
 * them by unwinding a domain transaction.
 *
 * It also never writes business state. It returns validated data; what
 * to do with it is the calling application service's decision, subject
 * to that domain's own rules and human review (docs/ai/README.md
 * §AI safety rules).
 */
final class AiExecutionService implements AiExecutionServiceInterface
{
    public function __construct(
        private readonly AiFeatureGate $gate,
        private readonly AiPromptRegistryInterface $prompts,
        private readonly AiSchemaRegistryInterface $schemas,
        private readonly AiProviderResolver $providers,
        private readonly AiModelResolver $models,
        private readonly AiBudgetGuard $budget,
        private readonly AiRunRecorder $recorder,
        private readonly StructuredOutputValidator $validator,
        private readonly AiLogger $log,
        private readonly AiSettings $settings,
    ) {}

    public function execute(AiTaskRequest $request): AiTaskResult
    {
        // ── Preflight: everything that must not cost money ────────────
        $blocked = $this->gate->blockReason($request->feature) ?? $this->budget->blockReason();

        if ($blocked !== null) {
            return $this->blocked($request, $blocked);
        }

        try {
            $prompt = $this->prompts->get($request->promptKey, $request->promptVersion);
            $provider = $this->providers->resolve($request->capability);
            $model = $this->models->resolve($request->modelRole ?? $prompt->resolvedModelRole());
            $rendered = $prompt->render($request->variables);
        } catch (AiException $e) {
            return $this->blocked($request, $e->failureCode);
        }

        $run = $this->recorder->start($request, $provider->name(), $model, $prompt->key, $prompt->version);

        $startedAt = hrtime(true);

        try {
            [$text, $payload, $moderation, $embedding, $usage] = $this->call($request, $provider, $rendered, $model);
        } catch (AiException $e) {
            return $this->failed($run, $e->failureCode, $this->elapsedMs($startedAt));
        } catch (Throwable) {
            // Never let an unclassified throwable escape with its
            // message: it may quote the request, and the request is
            // student content.
            return $this->failed($run, AiFailureCode::Unknown, $this->elapsedMs($startedAt));
        }

        $latencyMs = $this->elapsedMs($startedAt);

        // ── Structured output must clear its schema before it counts ──
        if ($request->capability === AiCapability::StructuredGeneration) {
            try {
                $payload = $this->validator->validate($payload ?? [], $this->schemas->get((string) $prompt->schemaKey));
            } catch (AiSchemaValidationException $e) {
                $this->recorder->rejected($run, $usage, $latencyMs, $e->failureCode);
                $this->log->warning('AI response rejected by schema validation', $this->context($run, $latencyMs, $usage) + [
                    'failure_code' => $e->failureCode->value,
                    'status' => AiRunStatus::Rejected->value,
                ]);

                return new AiTaskResult(
                    runId: $run->getKey(),
                    status: AiRunStatus::Rejected,
                    usage: $usage,
                    latencyMs: $latencyMs,
                    failureCode: $e->failureCode,
                );
            } catch (AiException $e) {
                return $this->failed($run, $e->failureCode, $latencyMs, $usage);
            }
        }

        $run = $this->recorder->succeeded($run, $usage, $latencyMs);

        $this->log->info('AI run succeeded', $this->context($run, $latencyMs, $usage) + [
            'status' => AiRunStatus::Succeeded->value,
        ]);

        return new AiTaskResult(
            runId: $run->getKey(),
            status: AiRunStatus::Succeeded,
            usage: $usage,
            latencyMs: $latencyMs,
            text: $text,
            data: $payload,
            moderation: $moderation,
            embedding: $embedding,
        );
    }

    /**
     * Dispatches to the capability contract. The provider is already
     * known to support it (AiProviderResolver checked), so the
     * instanceof guards are defence in depth against a misdeclared
     * capabilities() rather than routine control flow.
     *
     * @return array{0: ?string, 1: ?array<string, mixed>, 2: ?AiModerationResult, 3: ?AiEmbeddingResult, 4: AiUsage}
     */
    private function call(AiTaskRequest $request, object $provider, RenderedPrompt $prompt, string $model): array
    {
        $timeout = $this->settings->request_timeout_seconds;
        $definition = $prompt->definition;

        switch ($request->capability) {
            case AiCapability::TextGeneration:
                if (! $provider instanceof TextGenerationProviderInterface) {
                    throw AiConfigurationException::capabilityUnsupported($provider::class, $request->capability->value);
                }

                $response = $provider->generateText(new AiTextRequest(
                    model: $model,
                    systemPrompt: $prompt->system,
                    userPrompt: $prompt->user,
                    maxOutputTokens: $definition->maxOutputTokens,
                    temperature: $definition->temperature,
                    timeoutSeconds: $timeout,
                ));

                return [$response->text, null, null, null, $response->usage];

            case AiCapability::StructuredGeneration:
                if (! $provider instanceof StructuredGenerationProviderInterface) {
                    throw AiConfigurationException::capabilityUnsupported($provider::class, $request->capability->value);
                }

                $schema = $this->schemas->get((string) $definition->schemaKey);

                $response = $provider->generateStructured(new AiStructuredRequest(
                    model: $model,
                    systemPrompt: $prompt->system,
                    userPrompt: $prompt->user,
                    schemaName: $schema->name(),
                    jsonSchema: $schema->jsonSchema(),
                    maxOutputTokens: $definition->maxOutputTokens,
                    temperature: $definition->temperature,
                    timeoutSeconds: $timeout,
                ));

                return [null, $response->payload, null, null, $response->usage];

            case AiCapability::Moderation:
                if (! $provider instanceof ModerationProviderInterface) {
                    throw AiConfigurationException::capabilityUnsupported($provider::class, $request->capability->value);
                }

                $result = $provider->moderate(new AiModerationRequest($model, $prompt->user, $timeout));

                return [null, null, $result, null, $result->usage];

            case AiCapability::Embedding:
                if (! $provider instanceof EmbeddingProviderInterface) {
                    throw AiConfigurationException::capabilityUnsupported($provider::class, $request->capability->value);
                }

                $result = $provider->embed(new AiEmbeddingRequest($model, [$prompt->user], $timeout));

                return [null, null, null, $result, $result->usage];
        }
    }

    private function blocked(AiTaskRequest $request, AiFailureCode $code): AiTaskResult
    {
        // Provider name is best-effort here: the block may be precisely
        // that no provider is configured.
        $provider = rescue(fn (): string => $this->providers->active()->name(), 'unresolved', report: false);

        $run = $this->recorder->blocked($request, $provider, $code);

        $this->log->info('AI run blocked before execution', [
            'run_id' => $run->getKey(),
            'feature' => $request->feature->value,
            'provider' => $provider,
            'prompt_key' => $request->promptKey,
            'status' => AiRunStatus::Blocked->value,
            'failure_code' => $code->value,
        ]);

        return new AiTaskResult(
            runId: $run->getKey(),
            status: AiRunStatus::Blocked,
            usage: AiUsage::none(),
            failureCode: $code,
        );
    }

    private function failed(AiRun $run, AiFailureCode $code, int $latencyMs, ?AiUsage $usage = null): AiTaskResult
    {
        $run = $this->recorder->failed($run, $code, $latencyMs, $usage);

        $this->log->warning('AI run failed', $this->context($run, $latencyMs, $usage ?? AiUsage::none()) + [
            'status' => AiRunStatus::Failed->value,
            'failure_code' => $code->value,
        ]);

        return new AiTaskResult(
            runId: $run->getKey(),
            status: AiRunStatus::Failed,
            usage: $usage ?? AiUsage::none(),
            latencyMs: $latencyMs,
            failureCode: $code,
        );
    }

    /** @return array<string, scalar|null> */
    private function context(AiRun $run, int $latencyMs, AiUsage $usage): array
    {
        return [
            'run_id' => $run->getKey(),
            'feature' => $run->getRawOriginal('feature_key'),
            'provider' => $run->provider,
            'model' => $run->model,
            'prompt_key' => $run->prompt_key,
            'prompt_version' => $run->prompt_version,
            'latency_ms' => $latencyMs,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
        ];
    }

    private function elapsedMs(float|int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
