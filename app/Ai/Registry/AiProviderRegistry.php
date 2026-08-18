<?php

declare(strict_types=1);

namespace App\Ai\Registry;

use App\Ai\Contracts\AiProviderInterface;
use App\Ai\Contracts\AiProviderRegistryInterface;
use App\Ai\Exceptions\AiConfigurationException;

/**
 * Registered AI providers, keyed for settings selection — the same
 * registry pattern the payout and meeting domains use.
 *
 * Adding Gemini or Claude later is one register() call in
 * AiServiceProvider plus the adapter itself; the settings dropdown,
 * the resolver and every business caller pick it up with no change.
 */
final class AiProviderRegistry implements AiProviderRegistryInterface
{
    /** @var array<string, AiProviderInterface> */
    private array $providers = [];

    public function register(AiProviderInterface $provider): void
    {
        $this->providers[$provider->name()] = $provider;
    }

    public function get(string $key): AiProviderInterface
    {
        return $this->providers[$key]
            ?? throw new AiConfigurationException(sprintf('AI provider "%s" is not registered.', $key));
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    public function options(): array
    {
        $options = [];

        foreach (array_keys($this->providers) as $key) {
            $options[$key] = match ($key) {
                'openai' => 'OpenAI',
                'fake' => 'Fake (no network — testing/staging only)',
                default => ucfirst($key),
            };
        }

        return $options;
    }
}
