<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\Exceptions\AiConfigurationException;
use App\Ai\Prompts\PromptDefinition;

/**
 * Prompts are versioned, registered artifacts — never string literals
 * inside a service. Every run records the key and version it used, so a
 * later evaluation can compare v1 against v2 on real traffic instead of
 * guessing which wording was live when an output looked wrong.
 */
interface AiPromptRegistryInterface
{
    public function register(PromptDefinition $definition): void;

    /** @throws AiConfigurationException when key/version is unknown */
    public function get(string $key, ?string $version = null): PromptDefinition;

    public function has(string $key, ?string $version = null): bool;

    /** @return list<PromptDefinition> */
    public function all(): array;
}
