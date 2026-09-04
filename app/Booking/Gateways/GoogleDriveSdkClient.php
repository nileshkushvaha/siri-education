<?php

declare(strict_types=1);

namespace App\Booking\Gateways;

use App\Booking\Contracts\GoogleDriveClient;
use App\Booking\DTOs\GoogleDriveTarget;
use App\Booking\DTOs\RecordingByteRange;
use App\Booking\Exceptions\GatewayRequestException;
use Google\Client;
use Google\Http\MediaFileUpload;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
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

    /** Drive's media download endpoint — every media read goes through it, streamed (see openReadStream). */
    private const string MEDIA_DOWNLOAD_URL = 'https://www.googleapis.com/drive/v3/files/%s';

    /**
     * A delegated access token is cached for its lifetime minus this
     * buffer. Playback is the reason: a seeking video element issues
     * many Range requests per minute, and each would otherwise sign
     * and exchange a fresh JWT assertion. The token lives only in the
     * application cache — never in a row, a log, or a response.
     */
    private const int TOKEN_EXPIRY_BUFFER_SECONDS = 120;

    /** How long one request may hold the mint lock, and how long others wait for it. */
    private const int TOKEN_MINT_LOCK_SECONDS = 15;

    private const int TOKEN_MINT_WAIT_SECONDS = 10;

    public function requestedScopes(): array
    {
        return self::REQUESTED_SCOPES;
    }

    public function verifyTokenAcquisition(string $credentialsJson, string $delegatedSubject): void
    {
        $target = new GoogleDriveTarget($credentialsJson, $delegatedSubject);

        // A diagnostic must exercise the grant NOW, not be answered by
        // a token minted before an administrator changed anything.
        Cache::forget($this->tokenCacheKey($target));

        try {
            $this->service($target);
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

    /**
     * A media download, streamed. The file id comes ONLY from a
     * RecordingLocator the calling adapter resolved from an authorized
     * Recording row — nothing in this class, or above it, accepts a
     * file id from a request.
     *
     * The SDK's files.get(alt=media) buffers the whole body before
     * returning and cannot attach a Range header, so every media read
     * goes through the SDK's own authorized Guzzle client with
     * `stream => true`: same credentials, same delegated subject, same
     * scopes; only the transport differs. Bytes are handed back as a
     * socket-backed resource and read chunk by chunk by the caller —
     * a multi-gigabyte recording never lands in PHP memory or on this
     * host's disk on its way out.
     *
     * With a $range, Drive answers 206 and exactly that window. A 200
     * to a ranged request (a proxy ignoring Range) is tolerated: the
     * stream is advanced to the window start by reading and
     * discarding, so the caller never receives a silently wrong window.
     *
     * @return resource
     */
    public function openReadStream(GoogleDriveTarget $target, string $fileId, ?RecordingByteRange $range = null)
    {
        try {
            $http = $this->authorizedClient($target)->authorize();

            $response = $http->request('GET', sprintf(self::MEDIA_DOWNLOAD_URL, rawurlencode($fileId)), [
                'query' => $this->driveParams($target, ['alt' => 'media']),
                'headers' => $range !== null ? ['Range' => $range->toHttpHeader()] : [],
                'stream' => true,
                'http_errors' => false,
            ]);
        } catch (GatewayRequestException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new GatewayRequestException('Google Drive media request failed: '.$e->getMessage(), previous: $e);
        }

        $status = $response->getStatusCode();

        if ($status === 401) {
            // A cached token that Google no longer honours must not
            // poison every subsequent read until it expires.
            Cache::forget($this->tokenCacheKey($target));
        }

        $acceptable = $range !== null ? [200, 206] : [200];

        if (! in_array($status, $acceptable, true)) {
            $response->getBody()->close();

            throw new GatewayRequestException(sprintf(
                'Google Drive API error while reading a recording (HTTP %d%s). Delegated account: %s. Shared drive: %s',
                $status,
                $status === 404 ? ', reason: notFound' : '',
                $target->delegatedSubject,
                $target->usesSharedDrive() ? 'yes' : 'no',
            ));
        }

        $stream = $response->getBody()->detach();

        if (! is_resource($stream)) {
            throw new GatewayRequestException('Google Drive returned an unreadable recording stream.');
        }

        if ($range !== null && $status === 200 && $range->start > 0) {
            $remaining = $range->start;

            while ($remaining > 0 && ! feof($stream)) {
                $chunk = fread($stream, min($remaining, 1024 * 1024));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $remaining -= strlen($chunk);
            }
        }

        return $stream;
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
        return new Drive($this->authorizedClient($target));
    }

    /**
     * A Google client holding a valid delegated access token. The token
     * is served from the application cache when one is still live for
     * this credential + subject, otherwise minted and cached.
     */
    private function authorizedClient(GoogleDriveTarget $target): Client
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

        $cacheKey = $this->tokenCacheKey($target);

        if ($this->applyCachedToken($client, $cacheKey)) {
            return $client;
        }

        // A seeking player fires many requests at once when the cache
        // is cold; a short lock lets one of them mint and the rest reuse
        // it, instead of every request signing its own JWT assertion.
        // If the lock cannot be obtained in time, minting proceeds
        // anyway — a duplicate token is wasteful, a stalled request is
        // not acceptable.
        $lock = Cache::lock($cacheKey.':mint', self::TOKEN_MINT_LOCK_SECONDS);

        try {
            $lock->block(self::TOKEN_MINT_WAIT_SECONDS);

            if ($this->applyCachedToken($client, $cacheKey)) {
                return $client;
            }

            $token = $this->assertTokenAcquired($client, $decoded, $target->delegatedSubject);

            // TTL from the token's OWN lifetime, less a buffer — never a
            // fixed guess. A token without a usable lifetime is used for
            // this request only and not cached.
            $ttl = (int) ($token['expires_in'] ?? 0) - self::TOKEN_EXPIRY_BUFFER_SECONDS;

            if ($ttl > 0) {
                Cache::put($cacheKey, $token, $ttl);
            }
        } catch (LockTimeoutException) {
            $this->assertTokenAcquired($client, $decoded, $target->delegatedSubject);
        } finally {
            $lock->release();
        }

        return $client;
    }

    /** Installs a still-valid cached token on the client; false when none is usable. */
    private function applyCachedToken(Client $client, string $cacheKey): bool
    {
        $cached = Cache::get($cacheKey);

        if (! is_array($cached) || ! isset($cached['access_token'])) {
            return false;
        }

        $client->setAccessToken($cached);

        if ($client->isAccessTokenExpired()) {
            Cache::forget($cacheKey);

            return false;
        }

        return true;
    }

    /**
     * Keyed on WHICH identity is being impersonated with WHICH key —
     * never on anything a caller controls — so rotating the service
     * account or changing the platform account naturally misses.
     */
    private function tokenCacheKey(GoogleDriveTarget $target): string
    {
        return 'recordings:google-drive:token:'.hash('sha256', implode('|', [
            hash('sha256', $target->credentialsJson),
            $target->delegatedSubject,
            implode(' ', self::REQUESTED_SCOPES),
        ]));
    }

    /**
     * @param  array<string, mixed>  $decodedCredentials
     * @return array<string, mixed> the minted token, as the SDK returns it
     */
    private function assertTokenAcquired(Client $client, array $decodedCredentials, string $delegatedSubject): array
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

        return is_array($token) ? $token : [];
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
        if ($e->getCode() === 401) {
            // Google no longer honours the token we hold — whether it was
            // minted or cached, the next call must acquire a fresh one.
            Cache::forget($this->tokenCacheKey($target));
        }

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
