<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\AiSchemaInterface;
use App\Ai\Exceptions\AiSchemaValidationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The gate between "the provider said something" and "the application
 * believes it".
 *
 * Everything a provider returns is untrusted input, exactly like a
 * request body: a model can hallucinate a field, drop a required one,
 * return a string where a number belongs, or emit extra keys. So the
 * response goes through the same Validator the application uses for
 * user input, and only the VALIDATED subset is returned — extra keys
 * are dropped rather than passed along, so a hallucinated field can
 * never reach a DTO by accident.
 *
 * On failure only the failing field NAMES travel into the exception;
 * values are model output derived from student content and are never
 * quoted back (see AiSchemaValidationException).
 */
final class StructuredOutputValidator
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> validated data only
     *
     * @throws AiSchemaValidationException
     */
    public function validate(array $payload, AiSchemaInterface $schema): array
    {
        try {
            return Validator::make($payload, $schema->rules())->validate();
        } catch (ValidationException $e) {
            throw new AiSchemaValidationException($schema->key(), array_keys($e->errors()));
        }
    }
}
