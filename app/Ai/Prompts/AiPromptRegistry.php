<?php

declare(strict_types=1);

namespace App\Ai\Prompts;

use App\Ai\Contracts\AiPromptRegistryInterface;
use App\Ai\Exceptions\AiConfigurationException;

/**
 * In-memory prompt catalogue, populated by AiServiceProvider from
 * AiPromptCatalog. Kept as a registry rather than a table because a
 * prompt is code: it must be reviewed, versioned in git, and deployed
 * with the schema and parsing that depend on it. An admin-editable
 * prompt row would let production wording drift away from the schema
 * the application validates against.
 *
 * Requesting a key without a version returns the highest registered
 * version, so a feature that pins nothing follows the current prompt
 * while a run row still records exactly which one it used.
 */
final class AiPromptRegistry implements AiPromptRegistryInterface
{
    /** @var array<string, array<string, PromptDefinition>> key => version => definition */
    private array $prompts = [];

    public function register(PromptDefinition $definition): void
    {
        $this->prompts[$definition->key][$definition->version] = $definition;
    }

    public function get(string $key, ?string $version = null): PromptDefinition
    {
        $versions = $this->prompts[$key] ?? [];

        if ($versions === []) {
            throw AiConfigurationException::promptMissing($key, $version);
        }

        if ($version === null) {
            uksort($versions, 'strnatcmp');

            return end($versions);
        }

        return $versions[$version] ?? throw AiConfigurationException::promptMissing($key, $version);
    }

    public function has(string $key, ?string $version = null): bool
    {
        if ($version === null) {
            return ($this->prompts[$key] ?? []) !== [];
        }

        return isset($this->prompts[$key][$version]);
    }

    public function all(): array
    {
        $flat = [];

        foreach ($this->prompts as $versions) {
            foreach ($versions as $definition) {
                $flat[] = $definition;
            }
        }

        return $flat;
    }
}
