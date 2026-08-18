<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use Carbon\CarbonImmutable;

/**
 * Everything a RecordingStorage backend needs to place one object,
 * and nothing more.
 *
 * Note what is absent: no student, instructor, email, phone, subject,
 * or booking model. A storage backend gets a NON-IDENTIFYING display
 * name and a partition date, because folder and file names on an
 * external service are not a place for PII — and because folder
 * structure must never become a second source of truth. The database
 * remains authoritative for who a recording belongs to.
 *
 * `displayName` is built by RecordingFileNamer from the booking's
 * public reference only.
 */
final readonly class RecordingStorageRequest
{
    public function __construct(
        public StagedRecordingFile $file,
        public string $displayName,
        public CarbonImmutable $partitionedAt,
    ) {}
}
