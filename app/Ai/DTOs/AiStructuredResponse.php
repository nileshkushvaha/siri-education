<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

/**
 * The decoded — but NOT yet validated — provider payload. Nothing may
 * read $payload directly except StructuredOutputValidator; application
 * services receive only validated data.
 */
final readonly class AiStructuredResponse
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public array $payload,
        public AiUsage $usage,
    ) {}
}
