<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\Enums\AiFailureCode;
use App\Ai\Enums\AiFeature;
use App\Settings\AiSettings;
use App\Settings\FeatureSettings;

/**
 * Decides whether an AI feature may run at all, in strict order:
 *
 *   1. the module master switch  (FeatureSettings::$ai_enabled)
 *   2. the capability flag       (AiSettings::*_enabled)
 *   3. a configured credential   (AiCredentialStore)
 *
 * FAILS CLOSED at every step. The ordering matters: an operator who
 * turns the module off must stop provider traffic instantly without
 * having to also clear four capability flags, and a feature whose flag
 * is on must still not call anything when no key exists.
 *
 * The 'fake' provider is exempt from the credential check — it is the
 * shipped default and the test double, and it reaches no network.
 */
final class AiFeatureGate
{
    public function __construct(
        private readonly FeatureSettings $features,
        private readonly AiSettings $settings,
        private readonly AiCredentialStore $credentials,
    ) {}

    public function enabled(AiFeature $feature): bool
    {
        return $this->blockReason($feature) === null;
    }

    /** Null means "may run"; otherwise the code recorded on the blocked run. */
    public function blockReason(AiFeature $feature): ?AiFailureCode
    {
        if (! $this->features->ai_enabled) {
            return AiFailureCode::FeatureDisabled;
        }

        $flag = $feature->settingsFlag();

        if ($flag !== null && ! (bool) $this->settings->{$flag}) {
            return AiFailureCode::FeatureDisabled;
        }

        if (! $this->configured()) {
            return AiFailureCode::NotConfigured;
        }

        return null;
    }

    /** True when the active provider has everything it needs to be called. */
    public function configured(): bool
    {
        if ($this->settings->provider === 'fake') {
            return true;
        }

        return $this->credentials->hasOpenAiApiKey();
    }
}
