<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\DTOs\AiEmbeddingRequest;
use App\Ai\DTOs\AiEmbeddingResult;
use App\Ai\Exceptions\AiProviderException;

/**
 * The RAG extension point, and nothing more. P0 deliberately ships the
 * contract with no storage behind it: no vector column, no vector
 * database, no embeddings table. A future phase that genuinely needs
 * retrieval decides its own storage then, with the data-protection
 * review that decision deserves.
 */
interface EmbeddingProviderInterface extends AiProviderInterface
{
    /** @throws AiProviderException */
    public function embed(AiEmbeddingRequest $request): AiEmbeddingResult;
}
