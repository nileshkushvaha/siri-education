<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

final readonly class AiTextResponse
{
    public function __construct(
        public string $text,
        public AiUsage $usage,
    ) {}
}
