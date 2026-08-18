<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

/**
 * A provider-neutral text-generation call. Carries a CONCRETE model
 * name because by this point AiModelResolver has already turned the
 * role from the prompt definition into the configured model — provider
 * adapters never read settings themselves.
 */
final readonly class AiTextRequest
{
    public function __construct(
        public string $model,
        public string $systemPrompt,
        public string $userPrompt,
        public int $maxOutputTokens,
        public float $temperature,
        public int $timeoutSeconds,
    ) {}
}
