<?php

declare(strict_types=1);

namespace App\Ai\Exceptions;

use App\Ai\Enums\AiFailureCode;

/**
 * A structured response did not match its schema.
 *
 * Only FIELD NAMES are retained ($violations), never the offending
 * values: the value is model output derived from the input, so quoting
 * it back into an exception message would defeat the whole point of not
 * persisting AI content.
 */
final class AiSchemaValidationException extends AiException
{
    /** @param list<string> $violations */
    public function __construct(
        public readonly string $schemaKey,
        public readonly array $violations,
    ) {
        parent::__construct(
            sprintf('AI response failed schema "%s" on: %s.', $schemaKey, implode(', ', $violations) ?: 'unknown fields'),
            AiFailureCode::SchemaValidationFailed,
        );
    }
}
