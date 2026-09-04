<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\RecordingByteRange;
use App\Booking\DTOs\RecordingLocator;
use App\Booking\DTOs\RecordingStorageRequest;
use App\Booking\DTOs\StoredRecording;
use App\Booking\Exceptions\RecordingStorageException;

/**
 * The provider-neutral boundary between the recording DOMAIN and
 * wherever the bytes physically live. Google Drive is the first
 * implementation; Amazon S3 is reached later through
 * FilesystemRecordingStorage pointed at the "s3" disk.
 *
 * Rules this contract exists to enforce:
 *
 *  - No SDK type crosses it. Implementations accept and return only
 *    the DTOs in App\Booking\DTOs — never a \Google\Service\Drive\
 *    DriveFile, an S3 result object, or a Flysystem adapter.
 *  - No backend identifier leaks. A Drive file id, an S3 key and a
 *    disk path are all just RecordingLocator::$path; nothing above
 *    this line parses it or builds a URL from it.
 *  - Every failure is a RecordingStorageException carrying a
 *    RecordingFailureCode, so the domain's retry decision never
 *    depends on which backend is active.
 *  - Access is never delegated to the backend. Nothing here issues a
 *    public link or a shareable URL; read() hands back a stream that
 *    the application serves only after its own authorization check.
 *
 * Adding a backend means implementing this interface and registering
 * it in RecordingStorageResolver. It means changing nothing else.
 */
interface RecordingStorage
{
    /** Stable identifier persisted as recordings.storage_driver. */
    public function key(): string;

    /**
     * Whether this backend has everything it needs to run right now.
     * Never performs I/O and never throws — a configuration
     * declaration only, so an unconfigured backend fails closed at
     * ingestion time rather than at application boot.
     */
    public function isConfigured(): bool;

    /**
     * Streams the staged file into the backend and returns its
     * locator. MUST NOT read the whole file into memory, and MUST NOT
     * grant public/anyone-with-the-link access to what it writes.
     *
     * @throws RecordingStorageException
     */
    public function put(RecordingStorageRequest $request): StoredRecording;

    /**
     * Confirms the object really is there and really matches what we
     * sent, WITHOUT downloading it back: existence plus backend-side
     * metadata (size, and a checksum where the backend supplies one).
     *
     * @throws RecordingStorageException when the object is missing or mismatched
     */
    public function verify(RecordingLocator $locator, int $expectedBytes, ?string $expectedChecksum = null): void;

    /**
     * A read stream for authenticated delivery. The caller has already
     * authorized the viewer; this method performs no access checks of
     * its own and must never be reachable without one.
     *
     * With a $range, the stream is positioned at $range->start and the
     * caller reads at most $range->length() bytes — this is what lets
     * a browser video element seek. How the window is honoured is the
     * backend's business (an HTTP Range header, an fseek); the caller
     * only ever sees a stream. A backend that cannot position a stream
     * natively must still return one that begins at the right offset.
     *
     * @return resource
     *
     * @throws RecordingStorageException
     */
    public function read(RecordingLocator $locator, ?RecordingByteRange $range = null);

    /**
     * Removes the object. Idempotent: deleting an already-absent
     * object is a success, so a retried retention sweep is safe.
     *
     * @throws RecordingStorageException on a real backend failure
     */
    public function delete(RecordingLocator $locator): void;
}
