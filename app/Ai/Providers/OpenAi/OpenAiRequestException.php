<?php

declare(strict_types=1);

namespace App\Ai\Providers\OpenAi;

use App\Ai\Enums\AiFailureCode;
use App\Ai\Exceptions\AiProviderException;

/**
 * Every OpenAI transport failure, already classified into a stable
 * AiFailureCode and already redacted. Nothing above this class ever
 * sees an HTTP status, a provider error body, or a header.
 */
final class OpenAiRequestException extends AiProviderException
{
    public function __construct(
        string $safeMessage,
        AiFailureCode $failureCode,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($safeMessage, $failureCode, $previous);
    }
}
