<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\Enums\AiModelRole;
use App\Ai\Exceptions\AiConfigurationException;
use App\Settings\AiSettings;

/**
 * Role → configured model name. The only bridge between the two, which
 * is what keeps model strings out of business code entirely: a feature
 * asks for AiModelRole::Fast and never learns what answered.
 */
final class AiModelResolver
{
    public function __construct(
        private readonly AiSettings $settings,
    ) {}

    /** @throws AiConfigurationException when the role has no model configured */
    public function resolve(AiModelRole $role): string
    {
        $model = match ($role) {
            AiModelRole::Generation => $this->settings->generation_model,
            AiModelRole::Fast => $this->settings->fast_model,
            AiModelRole::Embedding => $this->settings->embedding_model,
            AiModelRole::Moderation => $this->settings->moderation_model,
        };

        if (blank($model)) {
            throw AiConfigurationException::notConfigured(sprintf('no model is set for the %s role', $role->value));
        }

        return $model;
    }
}
