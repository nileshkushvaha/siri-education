<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\DTOs\AiStructuredRequest;
use App\Ai\DTOs\AiStructuredResponse;
use App\Ai\Exceptions\AiProviderException;

/**
 * Generation constrained to a declared JSON schema. The adapter's job
 * ends at "this is valid JSON of the expected envelope" — it returns
 * the decoded payload and never interprets it. Schema conformance is
 * proven separately by StructuredOutputValidator.
 */
interface StructuredGenerationProviderInterface extends AiProviderInterface
{
    /** @throws AiProviderException */
    public function generateStructured(AiStructuredRequest $request): AiStructuredResponse;
}
