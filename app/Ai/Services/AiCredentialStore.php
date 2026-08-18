<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\Exceptions\AiConfigurationException;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * THE ONLY PLACE an AI credential is decrypted.
 *
 * Concentrating it here is what makes the security rules checkable:
 * nothing else reads AiSettings::$openai_api_key (an architecture test
 * asserts it), the plaintext exists only inside a method call, it is
 * never returned to a Livewire payload, never placed in an exception
 * message, and never logged — AiLogger's allowlist has no key that
 * could carry it.
 *
 * hasApiKey() exists so the settings page and the feature gate can ask
 * "is a credential configured?" without ever obtaining one.
 */
final class AiCredentialStore
{
    public function __construct(
        private readonly AiSettings $settings,
    ) {}

    public function hasOpenAiApiKey(): bool
    {
        return filled($this->settings->openai_api_key);
    }

    /** @throws AiConfigurationException when no key is configured */
    public function openAiApiKey(): string
    {
        $stored = $this->settings->openai_api_key;

        if (blank($stored)) {
            throw AiConfigurationException::notConfigured('the OpenAI API key is missing');
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            // A value that will not decrypt is either a pre-encryption
            // legacy row or an APP_KEY rotation. Returning it as-is
            // matches how the RazorpayX client handles the same case:
            // the provider rejects a bad key and the failure surfaces as
            // AuthenticationFailed, which is accurate and actionable —
            // far better than an opaque decryption crash.
            return $stored;
        }
    }

    public function openAiOrganization(): ?string
    {
        return filled($this->settings->openai_organization) ? $this->settings->openai_organization : null;
    }
}
