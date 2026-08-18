<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

/**
 * A recording that already sits inside a storage backend SIRI can also
 * write to — so it can be moved backend-side instead of being pulled
 * through this server.
 *
 * The concrete case is Google Meet: Meet writes its recording as an MP4
 * into Google Drive, and Google Drive is also (currently) SIRI's
 * recording storage. Downloading several gigabytes from Drive only to
 * upload the same bytes back to Drive would be pure waste.
 *
 * `driver` matches a RecordingStorage::key(). It is what lets the
 * ingestion pipeline ask a neutral question — "is the source already
 * in the same backend I am about to write to?" — without either side
 * naming Google. When storage later becomes S3, `driver` stops
 * matching, no backend supports the source natively, and the pipeline
 * silently falls back to streaming. Nothing needs changing for that to
 * happen.
 *
 * `reference` is opaque to everything except the backend that owns it.
 */
final readonly class NativeRecordingSource
{
    public function __construct(
        public string $driver,
        public string $reference,
    ) {}
}
