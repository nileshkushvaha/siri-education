<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\Exceptions\AiConfigurationException;

interface AiProviderRegistryInterface
{
    public function register(AiProviderInterface $provider): void;

    /** @throws AiConfigurationException when the key is unknown */
    public function get(string $key): AiProviderInterface;

    public function has(string $key): bool;

    /** @return array<string, string> provider key => display label */
    public function options(): array;
}
