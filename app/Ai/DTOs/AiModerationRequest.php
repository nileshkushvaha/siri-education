<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

final readonly class AiModerationRequest
{
    public function __construct(
        public string $model,
        public string $content,
        public int $timeoutSeconds,
    ) {}
}
