<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

/**
 * An operator-supplied object a storage backend has verified it can
 * read: the native source to copy, plus the metadata the backend
 * reported about it (used for safety limits and the stored row, never
 * trusted over what the copy reports afterwards).
 */
final readonly class ResolvedExternalSource
{
    public function __construct(
        public NativeRecordingSource $source,
        public ?int $sizeBytes,
        public ?string $mimeType,
    ) {}
}
