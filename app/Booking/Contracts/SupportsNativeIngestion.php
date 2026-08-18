<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\NativeIngestionRequest;
use App\Booking\DTOs\NativeRecordingSource;
use App\Booking\DTOs\StoredRecording;
use App\Booking\Exceptions\RecordingStorageException;

/**
 * An OPTIONAL capability a RecordingStorage may also implement: taking
 * ownership of an object that already lives inside the same backend,
 * without the bytes ever travelling through this server.
 *
 * Deliberately a SEPARATE interface rather than a method on
 * RecordingStorage. Native ingestion is an optimization available only
 * when source and destination happen to coincide; making it part of
 * the core contract would force every future backend — S3 included —
 * to implement something it cannot do. Optionality here is what keeps
 * RecordingStorage implementable in a dozen lines.
 *
 * Implementations MUST:
 *  - COPY, never move. The provider's original artifact stays exactly
 *    where the provider put it, still reachable by the provider's own
 *    tooling and by whatever retention that service applies.
 *  - place the copy in the same private destination put() would have
 *    used, and grant it no public or link-based access.
 *  - be safe to call twice for the same source: a retry must not leave
 *    two copies behind.
 */
interface SupportsNativeIngestion
{
    /**
     * Whether this backend can ingest that source without a local
     * round-trip. Never performs I/O — a capability declaration, so a
     * mismatched source falls back to streaming rather than failing.
     */
    public function canIngestNatively(NativeRecordingSource $source): bool;

    /**
     * Copies the source into this backend's own recording area and
     * returns the locator of the COPY.
     *
     * @throws RecordingStorageException
     */
    public function ingestNatively(NativeIngestionRequest $request): StoredRecording;
}
