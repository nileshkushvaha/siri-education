<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

/**
 * A generation call whose response must arrive as JSON matching
 * $jsonSchema. The schema is sent to the provider so it can constrain
 * decoding where it supports that, but that is an OPTIMISATION, not the
 * guarantee: StructuredOutputValidator re-validates every response
 * locally, because a provider honouring its own schema parameter is not
 * something this application will ever take on trust.
 */
final readonly class AiStructuredRequest
{
    /** @param array<string, mixed> $jsonSchema */
    public function __construct(
        public string $model,
        public string $systemPrompt,
        public string $userPrompt,
        public string $schemaName,
        public array $jsonSchema,
        public int $maxOutputTokens,
        public float $temperature,
        public int $timeoutSeconds,
    ) {}
}
