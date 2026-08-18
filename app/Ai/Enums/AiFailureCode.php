<?php

declare(strict_types=1);

namespace App\Ai\Enums;

/**
 * Why an AI run did not succeed, persisted as ai_runs.failure_code.
 *
 * A STABLE CODE IS ALL THAT IS EVER STORED — never the provider's raw
 * error message, which can echo back the prompt (and therefore student
 * content) or a credential fragment. The provider adapter classifies
 * its transport error into one of these and discards the rest.
 *
 * isRetryable() is the single retry decision, shared by
 * ExecuteAiTaskJob and any future synchronous caller. Anything not
 * explicitly transient is NOT retried: burning tokens on a request the
 * provider has already rejected on its merits is both wasteful and, for
 * ContentFiltered, actively wrong.
 */
enum AiFailureCode: string
{
    /** The capability's feature flag is off, or the AI module itself is off. */
    case FeatureDisabled = 'feature_disabled';

    /** No provider credentials / no model configured. */
    case NotConfigured = 'not_configured';

    /** The configured provider does not implement the requested capability. */
    case CapabilityUnsupported = 'capability_unsupported';

    /** The prompt key/version is not registered. */
    case PromptMissing = 'prompt_missing';

    /**
     * The feature has no entry in the AI feature registry, or the run
     * asked for a prompt, resolver or handler that entry does not
     * permit. Always a configuration or wiring mistake — never
     * something a retry or a different input could fix.
     */
    case FeatureNotPermitted = 'feature_not_permitted';

    /**
     * A feature declared as human-initiated was dispatched with no
     * acting user. Recorded rather than silently allowed, because it
     * means a human-facing capability has been wired to run in the
     * background.
     */
    case ActorRequired = 'actor_required';

    /** The daily or monthly cost ceiling is already reached. */
    case BudgetExceeded = 'budget_exceeded';

    /** The provider could not be reached at all (DNS, connection refused). */
    case ProviderUnavailable = 'provider_unavailable';

    /** Credentials were rejected. Never retried — a retry cannot fix a bad key. */
    case AuthenticationFailed = 'authentication_failed';

    case RateLimited = 'rate_limited';

    case Timeout = 'timeout';

    case ProviderServerError = 'provider_server_error';

    /** The provider rejected the request shape (bad model, malformed schema). */
    case InvalidRequest = 'invalid_request';

    /** A response arrived but was not parseable as the expected envelope. */
    case InvalidResponse = 'invalid_response';

    /** Parseable, but failed the declared schema. */
    case SchemaValidationFailed = 'schema_validation_failed';

    /** The provider's own safety systems refused the request or response. */
    case ContentFiltered = 'content_filtered';

    case Unknown = 'unknown';

    /**
     * Transient means "the same request could plausibly succeed in a
     * minute". SchemaValidationFailed and InvalidResponse qualify
     * because generation is non-deterministic — a second attempt often
     * returns well-formed output — but the job's bounded try budget
     * stops that from becoming an expensive loop.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::ProviderUnavailable,
            self::RateLimited,
            self::Timeout,
            self::ProviderServerError,
            self::InvalidResponse,
            self::SchemaValidationFailed => true,
            default => false,
        };
    }

    /** True when the run never reached the provider, so no tokens were spent. */
    public function isPreflight(): bool
    {
        return match ($this) {
            self::FeatureDisabled,
            self::NotConfigured,
            self::CapabilityUnsupported,
            self::PromptMissing,
            self::FeatureNotPermitted,
            self::ActorRequired,
            self::BudgetExceeded => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::FeatureDisabled => 'AI feature disabled',
            self::NotConfigured => 'AI not configured',
            self::CapabilityUnsupported => 'Capability unsupported by provider',
            self::PromptMissing => 'Prompt not registered',
            self::FeatureNotPermitted => 'AI feature not permitted by the registry',
            self::ActorRequired => 'AI feature requires an acting user',
            self::BudgetExceeded => 'AI budget exceeded',
            self::ProviderUnavailable => 'Provider unreachable',
            self::AuthenticationFailed => 'Provider credentials rejected',
            self::RateLimited => 'Provider rate limit reached',
            self::Timeout => 'Provider timed out',
            self::ProviderServerError => 'Provider server error',
            self::InvalidRequest => 'Provider rejected the request',
            self::InvalidResponse => 'Unreadable provider response',
            self::SchemaValidationFailed => 'Response failed schema validation',
            self::ContentFiltered => 'Blocked by provider safety filter',
            self::Unknown => 'Unknown AI failure',
        };
    }
}
