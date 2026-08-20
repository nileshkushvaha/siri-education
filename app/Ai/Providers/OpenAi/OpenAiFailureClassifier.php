<?php

declare(strict_types=1);

namespace App\Ai\Providers\OpenAi;

use App\Ai\Enums\AiFailureCode;

/**
 * HTTP status → retry decision, in one place so the adapter never
 * re-derives it and the classification can be unit tested without a
 * network.
 *
 * 401/403 is explicitly NOT retryable: retrying a rejected credential
 * only burns the rate limit and delays the alert an operator needs.
 * 400 likewise — the request shape will not fix itself.
 *
 * The error CODE is consulted before the status because OpenAI returns
 * 429 for two unrelated conditions: a transient rate limit, and an
 * exhausted account balance. Reading only the status would retry the
 * second one, which can never succeed.
 */
final class OpenAiFailureClassifier
{
    public static function fromStatus(?int $status, ?string $errorCode = null): AiFailureCode
    {
        $normalisedCode = $errorCode === null ? '' : strtolower($errorCode);

        if (str_contains($normalisedCode, 'content_filter')) {
            return AiFailureCode::ContentFiltered;
        }

        if (str_contains($normalisedCode, 'insufficient_quota') || str_contains($normalisedCode, 'billing_hard_limit_reached')) {
            return AiFailureCode::QuotaExhausted;
        }

        return match (true) {
            $status === null => AiFailureCode::ProviderUnavailable,
            $status === 401, $status === 403 => AiFailureCode::AuthenticationFailed,
            $status === 408 => AiFailureCode::Timeout,
            $status === 429 => AiFailureCode::RateLimited,
            $status >= 500 => AiFailureCode::ProviderServerError,
            $status >= 400 => AiFailureCode::InvalidRequest,
            default => AiFailureCode::Unknown,
        };
    }
}
