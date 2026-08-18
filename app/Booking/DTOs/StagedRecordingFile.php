<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use App\Booking\Services\RecordingStagingArea;

/**
 * A recording that has been fetched from the meeting provider and now
 * sits on the local private staging disk, ready to be streamed into a
 * RecordingStorage backend.
 *
 * This type exists so no layer ever holds a whole class video in a PHP
 * string. It is always produced by RecordingStagingArea (never
 * constructed from user input) and always deleted by the ingestion
 * pipeline's finally-block, success or failure.
 *
 * `checksum` is a sha256 of the staged bytes, computed while the file
 * is still local — it is the only integrity value we can compute
 * without re-downloading the object from the storage backend later.
 */
final readonly class StagedRecordingFile
{
    public function __construct(
        public string $absolutePath,
        public string $filename,
        public string $mimeType,
        public int $sizeBytes,
        public string $checksum,
    ) {}

    public function delete(): void
    {
        if (is_file($this->absolutePath)) {
            @unlink($this->absolutePath);
        }
    }

    /** @return resource */
    public function openStream()
    {
        $stream = @fopen($this->absolutePath, 'rb');

        if ($stream === false) {
            throw new \RuntimeException('Staged recording file is no longer readable.');
        }

        return $stream;
    }

    public function extension(): string
    {
        return RecordingStagingArea::extensionFor($this->mimeType, $this->filename);
    }
}
