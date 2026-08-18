<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\AiSchemaInterface;
use App\Ai\Contracts\AiSchemaRegistryInterface;
use App\Ai\Exceptions\AiConfigurationException;

final class AiSchemaRegistry implements AiSchemaRegistryInterface
{
    /** @var array<string, AiSchemaInterface> */
    private array $schemas = [];

    public function register(AiSchemaInterface $schema): void
    {
        $this->schemas[$schema->key()] = $schema;
    }

    public function get(string $key): AiSchemaInterface
    {
        return $this->schemas[$key]
            ?? throw new AiConfigurationException(sprintf('AI schema "%s" is not registered.', $key));
    }

    public function has(string $key): bool
    {
        return isset($this->schemas[$key]);
    }
}
