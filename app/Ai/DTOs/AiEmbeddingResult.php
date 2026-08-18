<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

/**
 * Vectors are returned to the caller and never persisted by this
 * module. P0 adds no embeddings table, no vector column and no vector
 * database — the capability exists so a future phase has a contract to
 * implement against, not because anything stores its output today.
 */
final readonly class AiEmbeddingResult
{
    /** @param list<list<float>> $vectors positionally aligned with the request inputs */
    public function __construct(
        public array $vectors,
        public AiUsage $usage,
    ) {}
}
