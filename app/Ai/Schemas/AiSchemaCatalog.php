<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\AiSchemaInterface;

/**
 * Every structured response shape the platform accepts. P1-P4 add
 * theirs here alongside the prompt that produces them — a prompt
 * referencing an unregistered schema fails closed at execution.
 */
final class AiSchemaCatalog
{
    /** @return list<AiSchemaInterface> */
    public static function schemas(): array
    {
        return [
            new ConnectivityCheckSchema,
        ];
    }
}
