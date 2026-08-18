<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\AiSchemaInterface;

/**
 * The only schema P0 ships: the response shape of the admin
 * connectivity check. Trivial on purpose — it exists to prove the
 * whole structured path (schema sent, response decoded, response
 * re-validated locally, run recorded) works end to end before any
 * feature depends on it.
 */
final class ConnectivityCheckSchema implements AiSchemaInterface
{
    public const string KEY = 'connectivity_check';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'connectivity_check';
    }

    public function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ok' => ['type' => 'boolean'],
            ],
            'required' => ['ok'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'ok' => ['required', 'boolean'],
        ];
    }
}
