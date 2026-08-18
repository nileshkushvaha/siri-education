<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use Carbon\CarbonImmutable;

/**
 * The backend-side counterpart of RecordingStorageRequest: same
 * destination decisions (non-identifying display name, date
 * partition), but the payload is a reference to an object already in
 * the backend rather than a file staged on local disk.
 *
 * Carries no PII, for the same reason RecordingStorageRequest does not
 * — see that class.
 */
final readonly class NativeIngestionRequest
{
    public function __construct(
        public NativeRecordingSource $source,
        public string $displayName,
        public CarbonImmutable $partitionedAt,
    ) {}
}
