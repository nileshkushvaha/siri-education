<?php

declare(strict_types=1);

namespace App\Queue\Enums;

/**
 * SRS-26-2: the bounded set of safe outcomes a
 * retry attempt can report. Never carries payload/exception content —
 * only the category.
 */
enum FailedJobRetryOutcome: string
{
    case Retried = 'retried';
    case NotFound = 'not_found';
    case UnsupportedConnection = 'unsupported_connection';
    case EnqueueFailed = 'enqueue_failed';

    public function label(): string
    {
        return match ($this) {
            self::Retried => 'Retried',
            self::NotFound => 'Not found (already retried or removed)',
            self::UnsupportedConnection => 'Unsupported connection',
            self::EnqueueFailed => 'Could not be re-queued',
        };
    }

    public function isSuccess(): bool
    {
        return $this === self::Retried;
    }
}
