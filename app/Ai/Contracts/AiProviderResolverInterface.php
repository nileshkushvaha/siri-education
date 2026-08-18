<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\Enums\AiCapability;
use App\Ai\Exceptions\AiConfigurationException;

/**
 * Turns "I need structured generation" into a concrete adapter, using
 * the admin-selected provider. The single place provider SELECTION
 * happens — nothing else may read AiSettings::$provider.
 */
interface AiProviderResolverInterface
{
    /** @throws AiConfigurationException when unconfigured or the provider lacks the capability */
    public function resolve(AiCapability $capability): AiProviderInterface;

    /** The active provider regardless of capability — for health checks and display. */
    public function active(): AiProviderInterface;
}
