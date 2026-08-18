<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

final readonly class AiEmbeddingRequest
{
    /** @param list<string> $inputs */
    public function __construct(
        public string $model,
        public array $inputs,
        public int $timeoutSeconds,
    ) {}
}
