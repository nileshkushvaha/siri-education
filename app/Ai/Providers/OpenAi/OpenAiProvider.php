<?php

declare(strict_types=1);

namespace App\Ai\Providers\OpenAi;

use App\Ai\Contracts\EmbeddingProviderInterface;
use App\Ai\Contracts\ModerationProviderInterface;
use App\Ai\Contracts\StructuredGenerationProviderInterface;
use App\Ai\Contracts\TextGenerationProviderInterface;
use App\Ai\DTOs\AiEmbeddingRequest;
use App\Ai\DTOs\AiEmbeddingResult;
use App\Ai\DTOs\AiModerationRequest;
use App\Ai\DTOs\AiModerationResult;
use App\Ai\DTOs\AiProviderCapabilities;
use App\Ai\DTOs\AiProviderHealth;
use App\Ai\DTOs\AiStructuredRequest;
use App\Ai\DTOs\AiStructuredResponse;
use App\Ai\DTOs\AiTextRequest;
use App\Ai\DTOs\AiTextResponse;
use App\Ai\DTOs\AiUsage;
use App\Ai\Enums\AiFailureCode;
use Throwable;

/**
 * The OpenAI adapter — the first implementation of the AI contracts,
 * and the ONLY place in the application that knows OpenAI's request and
 * response shapes.
 *
 * It normalizes everything into the neutral DTOs: token counts become
 * AiUsage, refusals and malformed output become AiFailureCode, and the
 * caller never learns which endpoint answered. That is what makes the
 * provider swappable — a Gemini adapter implements the same four
 * interfaces and nothing above this layer changes.
 *
 * Model names arrive as arguments, never as constants here: they come
 * from AiSettings via AiModelResolver.
 */
final class OpenAiProvider implements EmbeddingProviderInterface, ModerationProviderInterface, StructuredGenerationProviderInterface, TextGenerationProviderInterface
{
    public function __construct(
        private readonly OpenAiClientInterface $client,
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function capabilities(): AiProviderCapabilities
    {
        return new AiProviderCapabilities(
            textGeneration: true,
            structuredGeneration: true,
            moderation: true,
            embedding: true,
        );
    }

    public function healthCheck(): AiProviderHealth
    {
        try {
            // Lists models: the cheapest call that proves the credential
            // is valid, and it sends no prompt — an operator can verify
            // configuration without any content leaving the platform.
            $this->client->get('/models', ['limit' => 1], 10);

            return new AiProviderHealth(healthy: true);
        } catch (OpenAiRequestException $e) {
            return new AiProviderHealth(
                healthy: false,
                safeMessage: $e->failureCode === AiFailureCode::AuthenticationFailed
                    ? 'OpenAI rejected the configured API key.'
                    : 'OpenAI could not be reached.',
            );
        } catch (Throwable) {
            return new AiProviderHealth(healthy: false, safeMessage: 'OpenAI could not be reached.');
        }
    }

    public function generateText(AiTextRequest $request): AiTextResponse
    {
        $response = $this->client->post('/chat/completions', [
            'model' => $request->model,
            'messages' => $this->messages($request->systemPrompt, $request->userPrompt),
            'max_completion_tokens' => $request->maxOutputTokens,
            'temperature' => $request->temperature,
        ], $request->timeoutSeconds);

        $content = $this->firstMessageContent($response);

        return new AiTextResponse($content, $this->usage($response));
    }

    public function generateStructured(AiStructuredRequest $request): AiStructuredResponse
    {
        $response = $this->client->post('/chat/completions', [
            'model' => $request->model,
            'messages' => $this->messages($request->systemPrompt, $request->userPrompt),
            'max_completion_tokens' => $request->maxOutputTokens,
            'temperature' => $request->temperature,
            // Asking the provider to constrain decoding is an
            // optimisation, not the guarantee — StructuredOutputValidator
            // re-checks everything that comes back.
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $request->schemaName,
                    'strict' => true,
                    'schema' => $request->jsonSchema,
                ],
            ],
        ], $request->timeoutSeconds);

        $content = $this->firstMessageContent($response);
        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new OpenAiRequestException('OpenAI returned malformed JSON for a structured request.', AiFailureCode::InvalidResponse);
        }

        return new AiStructuredResponse($decoded, $this->usage($response));
    }

    public function moderate(AiModerationRequest $request): AiModerationResult
    {
        $response = $this->client->post('/moderations', [
            'model' => $request->model,
            'input' => $request->content,
        ], $request->timeoutSeconds);

        $result = $response->body['results'][0] ?? null;

        if (! is_array($result)) {
            throw new OpenAiRequestException('OpenAI returned no moderation result.', AiFailureCode::InvalidResponse);
        }

        $categories = array_keys(array_filter((array) ($result['categories'] ?? []), fn ($flagged): bool => (bool) $flagged));
        $scores = array_map('floatval', array_values((array) ($result['category_scores'] ?? [])));

        return new AiModerationResult(
            flagged: (bool) ($result['flagged'] ?? false),
            categories: array_values(array_map('strval', $categories)),
            highestScore: $scores === [] ? 0.0 : max($scores),
            // The moderation endpoint does not bill tokens; the request
            // id is still worth carrying for support.
            usage: new AiUsage(providerRequestId: $response->requestId),
        );
    }

    public function embed(AiEmbeddingRequest $request): AiEmbeddingResult
    {
        $response = $this->client->post('/embeddings', [
            'model' => $request->model,
            'input' => $request->inputs,
        ], $request->timeoutSeconds);

        $vectors = [];

        foreach ((array) ($response->body['data'] ?? []) as $item) {
            $vectors[] = array_map('floatval', (array) ($item['embedding'] ?? []));
        }

        return new AiEmbeddingResult($vectors, $this->usage($response));
    }

    /** @return list<array{role: string, content: string}> */
    private function messages(string $system, string $user): array
    {
        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    private function firstMessageContent(OpenAiApiResponse $response): string
    {
        $choice = $response->body['choices'][0] ?? null;

        if (is_array($choice) && ($choice['finish_reason'] ?? null) === 'content_filter') {
            throw new OpenAiRequestException('OpenAI blocked the request with its safety filter.', AiFailureCode::ContentFiltered);
        }

        $content = is_array($choice) ? ($choice['message']['content'] ?? null) : null;

        if (! is_string($content) || $content === '') {
            throw new OpenAiRequestException('OpenAI returned an empty completion.', AiFailureCode::InvalidResponse);
        }

        return $content;
    }

    private function usage(OpenAiApiResponse $response): AiUsage
    {
        $usage = (array) ($response->body['usage'] ?? []);

        return new AiUsage(
            inputTokens: (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
            providerRequestId: $response->requestId,
        );
    }
}
