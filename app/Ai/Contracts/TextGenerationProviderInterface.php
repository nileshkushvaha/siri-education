<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\DTOs\AiTextRequest;
use App\Ai\DTOs\AiTextResponse;
use App\Ai\Exceptions\AiProviderException;

/**
 * Free-form generation. Reserved for prose a human will read and edit
 * (a suggested draft). Anything that feeds a decision, a field, or a
 * persisted record must use StructuredGenerationProviderInterface
 * instead, so the output can be schema-validated.
 */
interface TextGenerationProviderInterface extends AiProviderInterface
{
    /** @throws AiProviderException on any transport, auth or protocol failure */
    public function generateText(AiTextRequest $request): AiTextResponse;
}
