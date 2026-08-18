<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\GoogleDriveTarget;
use App\Booking\Exceptions\GatewayRequestException;

/**
 * Isolates the google/apiclient Drive SDK behind a plain-array seam,
 * exactly as GoogleCalendarClient already does for Calendar:
 * GoogleDriveRecordingStorage never touches \Google\Client or
 * \Google\Service\Drive, and no SDK object ever reaches persistence.
 * Tests bind a fake implementation instead of stubbing HTTP.
 *
 * Stateless and credential-agnostic: the decrypted service-account
 * JSON and the impersonated Workspace subject travel with each call
 * (mirroring RazorpayGatewayClient/GoogleCalendarClient) and are never
 * stored on the instance or logged.
 */
interface GoogleDriveClient
{
    /**
     * The single source of truth for the Drive OAuth scopes this
     * integration requests. Deliberately minimal — see
     * GoogleDriveSdkClient.
     *
     * @return list<string>
     */
    public function requestedScopes(): array;

    /**
     * Exchanges the service-account assertion for an access token and
     * discards it — diagnoses domain-wide-delegation problems (a
     * missing Drive scope grant, a revoked key) in isolation, before
     * any Drive request is attempted.
     *
     * @throws GatewayRequestException with a credential-free message
     */
    public function verifyTokenAcquisition(string $credentialsJson, string $delegatedSubject): void;

    /**
     * Returns the id of the child folder named $name under $parentId,
     * creating it if absent. Implementations must tolerate a
     * concurrent creator having won the race.
     *
     * @throws GatewayRequestException
     */
    public function resolveOrCreateFolder(GoogleDriveTarget $target, string $parentId, string $name): string;

    /**
     * Uploads $sourcePath as a RESUMABLE, chunked upload — the file is
     * read from disk a chunk at a time and never held whole in memory.
     *
     * @return array{id: string, size: ?int, mimeType: ?string, md5Checksum: ?string}
     *
     * @throws GatewayRequestException
     */
    public function uploadResumable(
        GoogleDriveTarget $target,
        string $parentFolderId,
        string $sourcePath,
        string $filename,
        string $mimeType,
        int $chunkBytes,
    ): array;

    /**
     * Minimal metadata for verification. Null when the file does not
     * exist (a legitimate answer, not an error).
     *
     * @return array{id: string, size: ?int, mimeType: ?string, md5Checksum: ?string, trashed: bool}|null
     *
     * @throws GatewayRequestException
     */
    public function getFile(GoogleDriveTarget $target, string $fileId): ?array;

    /**
     * Server-side copy of an existing Drive file into $parentFolderId,
     * under a new name. The bytes never leave Google's infrastructure —
     * this is what lets a Google Meet recording, which Meet already
     * wrote into Drive, become a SIRI-owned recording without being
     * pulled down to this host and pushed back up.
     *
     * COPY, never move: the original artifact stays exactly where Meet
     * put it, so Google's own tooling and the organizer's access to it
     * are untouched.
     *
     * @return array{id: string, name: ?string, size: ?int, mimeType: ?string, md5Checksum: ?string}
     *
     * @throws GatewayRequestException
     */
    public function copyFile(
        GoogleDriveTarget $target,
        string $sourceFileId,
        string $parentFolderId,
        string $name,
    ): array;

    /**
     * An open read stream for the file's content, for authenticated
     * application-proxied delivery. Never returns a shareable URL.
     *
     * @return resource
     *
     * @throws GatewayRequestException
     */
    public function openReadStream(GoogleDriveTarget $target, string $fileId);

    /**
     * Permanently deletes the file. Must succeed silently when the
     * file is already gone, so retention sweeps stay idempotent.
     *
     * @throws GatewayRequestException
     */
    public function deleteFile(GoogleDriveTarget $target, string $fileId): void;
}
