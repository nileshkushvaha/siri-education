<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\Exceptions\AiConfigurationException;

interface AiSchemaRegistryInterface
{
    public function register(AiSchemaInterface $schema): void;

    /** @throws AiConfigurationException when the key is unknown */
    public function get(string $key): AiSchemaInterface;

    public function has(string $key): bool;
}
