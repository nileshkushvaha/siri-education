<?php

declare(strict_types=1);

namespace App\Queue\DTOs;

use App\Queue\Enums\FailedJobRetryOutcome;

/**
 * The typed, safe result of one retry attempt.
 * `message` is always a generic, admin-safe string — never a raw
 * exception or payload fragment.
 */
final readonly class FailedJobRetryResult
{
    public function __construct(
        public FailedJobRetryOutcome $outcome,
        public string $uuid,
        public ?string $displayName = null,
        public string $message = '',
    ) {}

    public static function retried(string $uuid, ?string $displayName): self
    {
        return new self(FailedJobRetryOutcome::Retried, $uuid, $displayName, 'Job re-queued.');
    }

    public static function notFound(string $uuid): self
    {
        return new self(FailedJobRetryOutcome::NotFound, $uuid, null, 'This job was already retried or no longer exists.');
    }

    public static function unsupportedConnection(string $uuid, ?string $displayName): self
    {
        return new self(FailedJobRetryOutcome::UnsupportedConnection, $uuid, $displayName, 'This job\'s queue connection cannot be retried from here.');
    }

    public static function enqueueFailed(string $uuid, ?string $displayName): self
    {
        return new self(FailedJobRetryOutcome::EnqueueFailed, $uuid, $displayName, 'The job could not be re-queued. It remains in the failed list.');
    }
}
