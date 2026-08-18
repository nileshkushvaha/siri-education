<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

/**
 * A moderation verdict is ADVISORY. P4 will combine it with
 * deterministic rules and human review — it may never, on its own,
 * suspend a user, block an account, or delete a message
 * (docs/ai/README.md §AI safety rules).
 */
final readonly class AiModerationResult
{
    /** @param list<string> $categories provider category keys that tripped, never the content itself */
    public function __construct(
        public bool $flagged,
        public array $categories,
        public float $highestScore,
        public AiUsage $usage,
    ) {}
}
