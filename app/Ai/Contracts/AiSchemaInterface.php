<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

/**
 * A contract for one structured AI response shape, expressed twice on
 * purpose:
 *
 *  - jsonSchema() is sent to the provider so it can constrain decoding;
 *  - rules() is Laravel validation, applied locally to what came back.
 *
 * The local rules are authoritative. A provider that ignores its own
 * schema parameter, changes behaviour between model versions, or
 * returns a plausible-looking object with a missing field is caught by
 * rules(), not by trust.
 */
interface AiSchemaInterface
{
    /** Stable key, referenced from prompt definitions. */
    public function key(): string;

    /** Provider-facing schema name (alphanumeric/underscore). */
    public function name(): string;

    /** @return array<string, mixed> JSON Schema document */
    public function jsonSchema(): array;

    /** @return array<string, mixed> Laravel validation rules for the decoded payload */
    public function rules(): array;
}
