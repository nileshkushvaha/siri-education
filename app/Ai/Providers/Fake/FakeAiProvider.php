<?php

declare(strict_types=1);

namespace App\Ai\Providers\Fake;

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

/**
 * A provider that reaches no network, mirroring
 * FakeInstructorPayoutProvider in the payout domain.
 *
 * It is the SHIPPED DEFAULT (`ai.provider` = 'fake'), which is what
 * makes the module safe to enable before credentials exist: turning AI
 * on in staging exercises the full path — gate, budget, run row, schema
 * validation, queue — without a single external call or a cent of
 * spend. It is also what lets the test suite prove the execution
 * pipeline without faking HTTP.
 *
 * Its structured response is deliberately minimal and schema-shaped
 * only for the connectivity check; it is not a simulator, and no
 * business feature should ever be validated against its output.
 */
final class FakeAiProvider implements EmbeddingProviderInterface, ModerationProviderInterface, StructuredGenerationProviderInterface, TextGenerationProviderInterface
{
    public function name(): string
    {
        return 'fake';
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
        return new AiProviderHealth(healthy: true, safeMessage: 'Fake provider — no external call was made.');
    }

    public function generateText(AiTextRequest $request): AiTextResponse
    {
        return new AiTextResponse(
            'Fake AI response. Configure a real provider to generate content.',
            $this->usage(),
        );
    }

    public function generateStructured(AiStructuredRequest $request): AiStructuredResponse
    {
        // Emits only the properties the requested schema declares, so a
        // fake run exercises validation rather than bypassing it.
        $payload = [];

        foreach ((array) ($request->jsonSchema['properties'] ?? []) as $property => $definition) {
            $payload[$property] = match ((array) $definition ? ($definition['type'] ?? 'string') : 'string') {
                'boolean' => true,
                'integer' => 0,
                'number' => 0.0,
                'array' => [],
                'object' => new \stdClass,
                default => 'fake',
            };
        }

        return new AiStructuredResponse($payload, $this->usage());
    }

    public function moderate(AiModerationRequest $request): AiModerationResult
    {
        return new AiModerationResult(flagged: false, categories: [], highestScore: 0.0, usage: $this->usage());
    }

    public function embed(AiEmbeddingRequest $request): AiEmbeddingResult
    {
        return new AiEmbeddingResult(
            array_map(fn (): array => array_fill(0, 8, 0.0), $request->inputs),
            $this->usage(),
        );
    }

    /** Non-zero so cost/usage accounting is exercised, tiny so it never distorts a real budget. */
    private function usage(): AiUsage
    {
        return new AiUsage(inputTokens: 1, outputTokens: 1, providerRequestId: 'fake-request');
    }
}
