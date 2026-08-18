<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\Enums\AiFeature;
use App\Ai\Exceptions\AiConfigurationException;
use App\Ai\Registry\AiFeatureDefinition;

/**
 * The allowlist of AI features that may execute at all.
 *
 * FAILS CLOSED. A feature with no definition cannot run, so adding an
 * enum case — or dispatching a descriptor for one — is not enough to
 * make AI available. A developer must register what the feature reads,
 * what it may run, and where its output goes, in the owning domain's
 * service provider.
 */
interface AiFeatureRegistryInterface
{
    public function register(AiFeatureDefinition $definition): void;

    public function has(AiFeature $feature): bool;

    /** @throws AiConfigurationException when the feature was never registered */
    public function get(AiFeature $feature): AiFeatureDefinition;

    /** @return list<AiFeatureDefinition> */
    public function all(): array;
}
