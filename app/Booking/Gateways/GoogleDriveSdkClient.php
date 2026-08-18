<?php

declare(strict_types=1);

namespace App\Booking\Gateways;

use App\Booking\Contracts\GoogleDriveClient;
use App\Booking\DTOs\GoogleDriveTarget;
use App\Booking\Exceptions\GatewayRequestException;
use Google\Client;
use Google\Http\MediaFileUpload;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Exception\RequestException;
use Throwable;

/**
 * Wraps the official `google/apiclient` Drive service — the only class
 * in this codebase that instantiates \Google\Service\Drive. Mirrors
 * GoogleCalendarSdkClient's structure deliberately: same auth model,
 * same construction order, same credential-free error translation.
 *
 * AUTHENTICATION: service account + domain-wide delegation,
 * impersonating the platform Workspace account already configured for
 * Google Meet (MeetingSettings::platform_meeting_account). No
 * end-user OAuth screen exists and none is wanted — this is
 * SIRI-owned storage, not a "connect your Drive" feature — so there is
 * no refresh token to store or rotate. Construction order is
 * setAuthConfig() → setScopes() → setSubject(), unconditionally.
 *
 * SCOPE: drive.file ONLY. That scope grants access exclusively to
 * files this application itself created, so a compromised key cannot
 * read, alter, or delete anything else in the Workspace account — and
 * it is sufficient for upload, metadata verification, download, and
 * deletion of our own recordings. Adding a scope here without adding
 * the identical scope to the domain-wide delegation grant in the
 * Workspace admin console breaks token acquisition for EVERY scope
 * with `401 unauthorized_client`, not just the new one.
 *
 * Large files use MediaFileUpload in resumable mode: the staged file
 * is read one chunk at a time, so peak memory is the chunk size, not
 * the recording size.
 */
final class GoogleDriveSdkClient implements GoogleDriveClient
{
    /** Only files this app created. Never widen to DRIVE or DRIVE_READONLY. */
    /**
     * TWO scopes, for two genuinely different jobs — and the split is
     * the least-privilege result, not a convenience:
     *
     *  drive.file          write and manage the files THIS APP creates.
     *                      Covers the whole SIRI recording area:
     *                      folders, uploads, copies, verification,
     *                      retention deletion.
     *
     *  drive.meet.readonly READ files that GOOGLE MEET created. Needed
     *                      because a Meet recording is created by Meet,
     *                      not by this app, so drive.file cannot see it
     *                      — verified behaviour, not an assumption.
     *                      Read-only and confined to Meet-generated
     *                      artifacts, so it grants nothing over the
     *                      rest of the account's Drive.
     *
     * Deliberately NOT drive.readonly or drive: either would expose
     * every file the impersonated Workspace account can reach.
     *
     * Any change here must be mirrored exactly in the Workspace
     * domain-wide delegation grant, or token acquisition fails for
     * EVERY scope with `401 unauthorized_client`.
     */
    private const array REQUESTED_SCOPES = [Drive::DRIVE_FILE, Drive::DRIVE_MEET_READONLY];

    private const string FOLDER_MIME_TYPE = 'application/vnd.google-apps.folder';

    /** The metadata verification actually needs — never the full resource. */
    private const string FILE_FIELDS = 'id,size,mimeType,md5Checksum,trashed';

    public function requestedScopes(): array
    {
        return self::REQUESTED_SCOPES;
    }

    public function verifyTokenAcquisition(string $credentialsJson, string $delegatedSubject): void
    {
        try {
            $this->service(new GoogleDriveTarget($credentialsJson, $delegatedSubject));
        } catch (GatewayRequestException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }

    public function resolveOrCreateFolder(GoogleDriveTarget $target, string $parentId, string $name): string
    {
        try {
            $service = $this->service($target);
            $existing = $this->findFolder($service, $target, $parentId, $name);

            if ($existing !== null) {
                return $existing;
            }

            $folder = new DriveFile;
            $folder->setName($name);
            $folder->setMimeType(self::FOLDER_MIME_TYPE);
            $folder->setParents([$parentId]);

            $created = $service->files->create($folder, $this->driveParams($target, ['fields' => 'id']));

            return (string) $created->getId();
        } catch (GatewayRequestException $e) {
            throw $e;
        } catch (GoogleServiceException $e) {
            // A concurrent worker may have created the same folder
            // between our lookup and our create — Drive permits
            // duplicate names, so re-reading is both the correct
            // recovery and naturally idempotent.
            $raced = $this->findFolderQuietly($target, $parentId, $name);

            if ($raced !== null) {
                return $raced;
            }

            throw $this->translateApiException($e, $target, sprintf('resolving folder [%s]', $name));
        } catch (Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }

    public function uploadResumable(
        GoogleDriveTarget $target,
        string $parentFolderId,
        string $sourcePath,
        string $filename,
        string $mimeType,
        int $chunkBytes,
    ): array {
        $handle = null;

        try {
            $service = $this->service($target);
            $client = $service->getClient();

            $metadata = new DriveFile;
            $metadata->setName($filename);
            $metadata->setParents([$parentFolderId]);

            // Defer request execution so files->create() returns the
            // Request object MediaFileUpload drives, instead of
            // performing a single-shot upload of the whole file.
            $client->setDefer(true);

            try {
                $request = $service->files->create($metadata, $this->driveParams($target, ['fields' => self::FILE_FIELDS]));

                $upload = new MediaFileUpload(
                    $client,
                    $request,
                    $mimeType,
                    null,
                    true, // resumable
                    $this->normalizeChunkBytes($chunkBytes),
                );
                $upload->setFileSize((int) filesize($sourcePath));

                $handle = fopen($sourcePath, 'rb');

                if ($handle === false) {
                    throw new GatewayRequestException('Staged recording could not be opened for upload.');
                }

                $status = false;

                // One chunk in memory at a time — never the whole video.
                while (! $status && ! feof($handle)) {
                    $chunk = fread($handle, $this->normalizeChunkBytes($chunkBytes));

                    if ($chunk === false) {
                        throw new GatewayRequestException('Staged recording read failed mid-upload.');
                    }

                    $status = $upload->nextChunk($chunk);
                }
            } finally {
                $client->setDefer(false);
            }

            if (! $status instanceof DriveFile) {
                throw new GatewayRequestException('Google Drive resumable upload did not complete.');
            }

            return $this->fileToArray($status);
        } catch (GatewayRequestException $e) {
            throw $e;
        } catch (GoogleServiceException $e) {
            throw $this->translateApiException($e, $target, 'uploading a recording');
        } catch (Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    public function copyFile(
        GoogleDriveTarget $target,
        string $sourceFileId,
        string $parentFolderId,
        string $name,
    ): array {
        try {
            $copy = new DriveFile;
            $copy->setName($name);
            $copy->setParents([$parentFolderId]);

            $created = $this->service($target)->files->copy(
                $sourceFileId,
                $copy,
                $this->driveParams($target, ['fields' => self::FILE_FIELDS]),
            );

            return $this->fileToArray($created) + ['name' => $created->getName()];
        } catch (GatewayRequestException $e) {
            throw $e;
        } catch (GoogleServiceException $e) {
            throw $this->translateApiException($e, $target, sprintf('copying Meet recording into folder [%s]', $parentFolderId));
        } catch (Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }

    public function getFile(GoogleDriveTarget $target, string $fileId): ?array
    {
        try {
            $file = $this->service($target)->files->get(
                $fileId,
                $this->driveParams($target, ['fields' => self::FILE_FIELDS]),
            );

            return $this->fileToArray($file) + ['trashed' => (bool) $file->getTrashed()];
        } catch (GatewayRequestException $e) {
            throw $e;
        } catch (GoogleServiceException $e) {
            if ($e->getCode() === 404) {
                return null;
            }

            throw $this->translateApiException($e, $target, 'reading recording metadata');
        } catch (Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }

    public function openReadStream(GoogleDriveTarget $target, string $fileId)
    {
        try {
            $response = $this->service($target)->files->get(
                $fileId,
                $this->driveParams($target, ['alt' => 'media']),
            );

            // With alt=media the SDK hands back a PSR-7 response whose
            // body is a stream — detach it so the caller can hand it
            // straight to a StreamedResponse without buffering.
            $stream = $response->getBody()->detach();

            if (! is_resource($stream)) {
                throw new GatewayRequestException('Google Drive returned an unreadable recording stream.');
            }

            return $stream;
        } catch (GatewayRequestException $e) {
            throw $e;
        } catch (GoogleServiceException $e) {
            throw $this->translateApiException($e, $target, 'downloading a recording');
        } catch (Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }

    public function deleteFile(GoogleDriveTarget $target, string $fileId): void
    {
        try {
            $this->service($target)->files->delete($fileId, $this->driveParams($target));
        } catch (GatewayRequestException $e) {
            throw $e;
        } catch (GoogleServiceException $e) {
            if ($e->getCode() === 404) {
                return; // already gone — the intended end state
            }

            throw $this->translateApiException($e, $target, 'deleting a recording');
        } catch (Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }

    // ── Internals ──────────────────────────────────────────────────────

    private function findFolder(Drive $service, GoogleDriveTarget $target, string $parentId, string $name): ?string
    {
        $query = sprintf(
            "name = '%s' and mimeType = '%s' and trashed = false and '%s' in parents",
            $this->escapeQueryLiteral($name),
            self::FOLDER_MIME_TYPE,
            $this->escapeQueryLiteral($parentId),
        );

        $result = $service->files->listFiles($this->driveParams($target, [
            'q' => $query,
            'fields' => 'files(id)',
            'pageSize' => 1,
        ]));

        $files = $result->getFiles();

        return $files === [] ? null : (string) $files[0]->getId();
    }

    private function findFolderQuietly(GoogleDriveTarget $target, string $parentId, string $name): ?string
    {
        try {
            return $this->findFolder($this->service($target), $target, $parentId, $name);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Shared Drive support is not optional plumbing: without
     * supportsAllDrives (and driveId/corpora on listings) every
     * Shared Drive request fails as a bare 404. Applied centrally so
     * no call site can forget it.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function driveParams(GoogleDriveTarget $target, array $params = []): array
    {
        if (! $target->usesSharedDrive()) {
            return $params;
        }

        $params['supportsAllDrives'] = true;

        if (isset($params['q'])) {
            $params['includeItemsFromAllDrives'] = true;
            $params['corpora'] = 'drive';
            $params['driveId'] = $target->sharedDriveId;
        }

        return $params;
    }

    /** Google requires resumable chunks to be a multiple of 256 KiB. */
    private function normalizeChunkBytes(int $chunkBytes): int
    {
        $unit = 256 * 1024;
        $chunks = max(1, (int) floor($chunkBytes / $unit));

        return $chunks * $unit;
    }

    /** @return array{id: string, size: ?int, mimeType: ?string, md5Checksum: ?string} */
    private function fileToArray(DriveFile $file): array
    {
        return [
            'id' => (string) $file->getId(),
            'size' => $file->getSize() !== null ? (int) $file->getSize() : null,
            'mimeType' => $file->getMimeType(),
            'md5Checksum' => $file->getMd5Checksum(),
        ];
    }

    /** Drive query literals are single-quoted; only the quote and backslash need escaping. */
    private function escapeQueryLiteral(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /**
     * Same construction contract as GoogleCalendarSdkClient:
     * setAuthConfig() → setScopes() → setSubject(), then an explicit
     * token exchange so a delegation failure is diagnosed here rather
     * than surfacing as an opaque error from whichever Drive call ran
     * first.
     */
    private function service(GoogleDriveTarget $target): Drive
    {
        $decoded = json_decode($target->credentialsJson, true, flags: JSON_THROW_ON_ERROR);

        $client = new Client;
        $client->setApplicationName(config('app.name', 'Enterprise App').' Recordings');
        $client->setAuthConfig($decoded);
        $client->setScopes(self::REQUESTED_SCOPES);
        $client->setSubject($target->delegatedSubject);
        $client->setHttpClient(new \GuzzleHttp\Client([
            'timeout' => max(30, (int) config('recordings.google_drive.request_timeout_seconds', 600)),
        ]));

        $this->assertTokenAcquired($client, $decoded, $target->delegatedSubject);

        return new Drive($client);
    }

    /** @param array<string, mixed> $decodedCredentials */
    private function assertTokenAcquired(Client $client, array $decodedCredentials, string $delegatedSubject): void
    {
        try {
            $token = $client->fetchAccessTokenWithAssertion();
        } catch (Throwable $e) {
            throw $this->translateTokenFailure($e, $decodedCredentials, $delegatedSubject);
        }

        if (is_array($token) && isset($token['error'])) {
            throw new GatewayRequestException($this->tokenErrorMessage(
                (string) $token['error'],
                isset($token['error_description']) ? (string) $token['error_description'] : null,
                $decodedCredentials,
                $delegatedSubject,
            ));
        }
    }

    /** @param array<string, mixed> $decodedCredentials */
    private function translateTokenFailure(Throwable $e, array $decodedCredentials, string $delegatedSubject): GatewayRequestException
    {
        $error = 'token_request_failed';
        $description = null;

        // Only the response body's error fields — never the request,
        // which carries the signed JWT assertion.
        if ($e instanceof RequestException && $e->hasResponse()) {
            $body = json_decode((string) $e->getResponse()->getBody(), true);

            if (is_array($body)) {
                $error = (string) ($body['error'] ?? $error);
                $description = isset($body['error_description']) ? (string) $body['error_description'] : null;
            }
        }

        return new GatewayRequestException(
            $this->tokenErrorMessage($error, $description, $decodedCredentials, $delegatedSubject),
            previous: $e,
        );
    }

    /** @param array<string, mixed> $decodedCredentials */
    private function tokenErrorMessage(string $error, ?string $description, array $decodedCredentials, string $delegatedSubject): string
    {
        $parts = [
            sprintf('Google OAuth token error: %s', $error),
            $description !== null ? sprintf('Description: %s', $description) : null,
            sprintf('Client ID: %s', $decodedCredentials['client_id'] ?? 'unknown'),
            sprintf('Client email: %s', $decodedCredentials['client_email'] ?? 'unknown'),
            sprintf('Delegated subject: %s', $delegatedSubject),
            sprintf('Requested scopes: [%s]', implode(', ', self::REQUESTED_SCOPES)),
        ];

        return implode('. ', array_filter($parts, static fn (?string $part): bool => $part !== null));
    }

    /**
     * A safe, structured Drive-API diagnostic: HTTP status, Google's
     * reason and message, the delegated account, and whether a Shared
     * Drive was targeted. Never includes credentials, tokens, or the
     * raw API payload.
     */
    private function translateApiException(GoogleServiceException $e, GoogleDriveTarget $target, string $operation): GatewayRequestException
    {
        $errors = $e->getErrors();
        $reason = $errors[0]['reason'] ?? 'unknown';
        $message = $errors[0]['message'] ?? $e->getMessage();

        return new GatewayRequestException(implode('. ', [
            sprintf('Google Drive API error while %s (HTTP %d, reason: %s): %s', $operation, $e->getCode(), $reason, $message),
            sprintf('Delegated account: %s', $target->delegatedSubject),
            sprintf('Shared drive: %s', $target->usesSharedDrive() ? 'yes' : 'no'),
        ]), previous: $e);
    }
}
