<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use App\Models\Recording;

/**
 * A provider-NEUTRAL pointer to one stored recording object: which
 * backend holds it, and that backend's own opaque handle for it.
 *
 * `path` is deliberately untyped beyond "a string the owning driver
 * understands" — a Drive file id, a disk-relative path, an S3 key.
 * Nothing outside the driver may parse, split, or build URLs from it.
 * That is what keeps `$recording->google_drive_file_id` from ever
 * appearing in business logic.
 *
 * The driver travels WITH the path because storage backends change:
 * an object written to Drive must still be readable and deletable
 * after config('recordings.storage_driver') has moved to s3.
 */
final readonly class RecordingLocator
{
    public function __construct(
        public string $driver,
        public string $path,
    ) {}

    public static function fromRecording(Recording $recording): ?self
    {
        if ($recording->storage_driver === null || $recording->storage_path === null) {
            return null;
        }

        return new self($recording->storage_driver, $recording->storage_path);
    }
}
