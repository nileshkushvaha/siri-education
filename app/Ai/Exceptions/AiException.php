<?php

declare(strict_types=1);

namespace App\Ai\Exceptions;

use App\Ai\Enums\AiFailureCode;
use RuntimeException;

/**
 * Base for every AI-layer failure. Carries a classified
 * AiFailureCode so the execution service never has to inspect a
 * message to decide what happened.
 *
 * MESSAGE DISCIPLINE: an AI exception message must be safe to log and
 * safe to show an admin. It may name the operation, the model role and
 * the failure category; it may never contain a credential, a prompt, a
 * provider response body, or student content. Adapters redact before
 * constructing — see RedactsProviderMessages.
 */
class AiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly AiFailureCode $failureCode = AiFailureCode::Unknown,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
