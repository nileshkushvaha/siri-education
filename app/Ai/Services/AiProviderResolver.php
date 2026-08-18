<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\Contracts\AiProviderInterface;
use App\Ai\Contracts\AiProviderRegistryInterface;
use App\Ai\Contracts\AiProviderResolverInterface;
use App\Ai\Enums\AiCapability;
use App\Ai\Exceptions\AiConfigurationException;
use App\Settings\AiSettings;

/**
 * The single place provider SELECTION happens. Nothing else reads
 * AiSettings::$provider — an architecture test enforces that — so
 * "which provider is active" has exactly one answer, and switching
 * providers can never be half-applied across the codebase.
 *
 * Capability support is checked here rather than at the call site, so a
 * feature asking for moderation from a text-only provider fails with a
 * precise, recorded CapabilityUnsupported instead of a fatal type
 * error deep in a job.
 */
final class AiProviderResolver implements AiProviderResolverInterface
{
    public function __construct(
        private readonly AiSettings $settings,
        private readonly AiProviderRegistryInterface $registry,
    ) {}

    public function resolve(AiCapability $capability): AiProviderInterface
    {
        $provider = $this->active();

        if (! $provider->capabilities()->supports($capability)) {
            throw AiConfigurationException::capabilityUnsupported($provider->name(), $capability->value);
        }

        return $provider;
    }

    public function active(): AiProviderInterface
    {
        $key = $this->settings->provider;

        if (blank($key)) {
            throw AiConfigurationException::notConfigured('no AI provider is selected');
        }

        return $this->registry->get($key);
    }
}
