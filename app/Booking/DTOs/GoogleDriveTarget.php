<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

/**
 * The credentials + destination a single Drive call runs against.
 *
 * Bundled into one value object so every GoogleDriveClient method
 * takes the same three-to-four parameters without repeating them, and
 * so the Shared Drive id travels with the credentials it applies to —
 * a Shared Drive request that forgets supportsAllDrives/driveId fails
 * with an opaque 404, which is exactly the class of bug this prevents.
 *
 * Never persisted, never logged, never serialized onto a queue: it
 * carries the decrypted service-account JSON.
 */
final readonly class GoogleDriveTarget
{
    public function __construct(
        public string $credentialsJson,
        public string $delegatedSubject,
        /** Null for My Drive (of the delegated subject); set for a Workspace Shared Drive. */
        public ?string $sharedDriveId = null,
    ) {}

    public function usesSharedDrive(): bool
    {
        return $this->sharedDriveId !== null && $this->sharedDriveId !== '';
    }
}
