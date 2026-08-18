<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

/**
 * What RecordingStorage::put() reports back after writing an object.
 *
 * `remoteSizeBytes` / `remoteChecksum` are what the BACKEND says it
 * holds, not what we sent — that distinction is the whole point:
 * RecordingIngestionService compares them against the staged file
 * before a recording is ever marked Available. A backend that cannot
 * supply a checksum returns null, and verification falls back to
 * size + existence (see RecordingStorage::verify()).
 */
final readonly class StoredRecording
{
    public function __construct(
        public RecordingLocator $locator,
        public ?int $remoteSizeBytes = null,
        public ?string $remoteChecksum = null,
    ) {}
}
