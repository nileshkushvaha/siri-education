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
 */
final class OpenAiFailureClassifier
{
    public static function fromStatus(?int $status, ?string $errorCode = null): AiFailureCode
    {
        if ($errorCode !== null && str_contains(strtolower($errorCode), 'content_filter')) {
            return AiFailureCode::ContentFiltered;
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
