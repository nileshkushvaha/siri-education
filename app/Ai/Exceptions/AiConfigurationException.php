<?php

declare(strict_types=1);

namespace App\Ai\Exceptions;

use App\Ai\Enums\AiFailureCode;

/** Missing credentials, unknown provider, unregistered prompt or schema. */
final class AiConfigurationException extends AiException
{
    public static function notConfigured(string $what): self
    {
        return new self("AI is not configured: {$what}.", AiFailureCode::NotConfigured);
    }

    public static function promptMissing(string $key, ?string $version): self
    {
        return new self(
            sprintf('AI prompt "%s" (%s) is not registered.', $key, $version ?? 'current'),
            AiFailureCode::PromptMissing,
        );
    }

    public static function capabilityUnsupported(string $provider, string $capability): self
    {
        return new self(
            sprintf('AI provider "%s" does not support %s.', $provider, $capability),
            AiFailureCode::CapabilityUnsupported,
        );
    }
}
