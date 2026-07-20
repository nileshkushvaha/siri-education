<?php

declare(strict_types=1);

namespace App\Queue\Support;

/**
 * Phase 24N — GAP-034 (Step 5): parses a failed_jobs.payload string
 * defensively for DISPLAY only — never unserializes the PHP object in
 * `payload.data.command`. Laravel's own dispatch pipeline already
 * writes a plain-string `displayName` field into the payload JSON
 * (CallQueuedHandler/Illuminate\Queue\Jobs\Job::getName()-equivalent),
 * so a safe class-like name is available without ever touching the
 * serialized/encrypted command. A malformed or unexpected payload
 * shape degrades to null fields rather than throwing.
 */
final class FailedJobPayloadSummary
{
    public function __construct(
        public readonly ?string $displayName,
        public readonly bool $isMalformed,
    ) {}

    public static function fromRawPayload(string $payload): self
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return new self(displayName: null, isMalformed: true);
        }

        $displayName = $decoded['displayName'] ?? null;

        return new self(
            displayName: is_string($displayName) && $displayName !== '' ? $displayName : null,
            isMalformed: false,
        );
    }

    /** First line only, hard-truncated — mirrors QueueMonitorPage's existing exception-preview convention. */
    public static function exceptionSummary(string $exception, int $limit = 160): string
    {
        return str($exception)->before("\n")->limit($limit)->toString();
    }
}
