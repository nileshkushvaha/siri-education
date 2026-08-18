<?php

declare(strict_types=1);

namespace App\Ai\Registry;

use App\Ai\Contracts\AiFeatureRegistryInterface;
use App\Ai\Enums\AiFeature;
use App\Ai\Exceptions\AiConfigurationException;

/**
 * In-memory feature allowlist, populated by each owning domain's
 * service provider — the same pattern as prompts and schemas, and for
 * the same reason: app/Ai never learns that a feature exists, so the
 * platform layer stays reusable and swappable.
 *
 * Registration is deliberately not idempotent-by-overwrite: a second
 * definition for the same feature is a mistake worth surfacing (two
 * domains each believing they own a capability), not something to
 * silently resolve in favour of whichever provider booted last.
 */
final class AiFeatureRegistry implements AiFeatureRegistryInterface
{
    /** @var array<string, AiFeatureDefinition> */
    private array $definitions = [];

    public function register(AiFeatureDefinition $definition): void
    {
        $key = $definition->feature->value;

        if (isset($this->definitions[$key]) && $this->definitions[$key] != $definition) {
            throw new AiConfigurationException(sprintf(
                'AI feature "%s" is already registered by %s and cannot be redefined by %s.',
                $key,
                $this->definitions[$key]->ownerDomain,
                $definition->ownerDomain,
            ));
        }

        $this->definitions[$key] = $definition;
    }

    public function has(AiFeature $feature): bool
    {
        return isset($this->definitions[$feature->value]);
    }

    public function get(AiFeature $feature): AiFeatureDefinition
    {
        return $this->definitions[$feature->value]
            ?? throw new AiConfigurationException(sprintf(
                'AI feature "%s" is not registered. Declare it in its owning domain\'s service provider before it can run.',
                $feature->value,
            ));
    }

    public function all(): array
    {
        return array_values($this->definitions);
    }
}
