<?php

declare(strict_types=1);

namespace App\Booking\Storage;

use App\Booking\Contracts\GoogleDriveClient;
use App\Booking\Contracts\RecordingStorage;
use App\Booking\Contracts\SupportsNativeIngestion;
use App\Booking\DTOs\GoogleDriveTarget;
use App\Booking\DTOs\NativeIngestionRequest;
use App\Booking\DTOs\NativeRecordingSource;
use App\Booking\DTOs\RecordingLocator;
use App\Booking\DTOs\RecordingStorageRequest;
use App\Booking\DTOs\StoredRecording;
use App\Booking\Enums\RecordingFailureCode;
use App\Booking\Exceptions\GatewayRequestException;
use App\Booking\Exceptions\RecordingStorageException;
use App\Settings\MeetingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Google Drive as the FIRST implementation of RecordingStorage —
 * explicitly not the architecture. Everything Drive-specific stops
 * here: the file id lives on in the row only as the opaque
 * RecordingLocator::$path, and no caller above this class knows what
 * shape it has.
 *
 * Authentication reuses the Google Workspace service account already
 * configured for Google Meet (same encrypted credential JSON, same
 * impersonated platform account), with domain-wide delegation and the
 * drive.file scope only. See GoogleDriveSdkClient.
 *
 * Also implements SupportsNativeIngestion, which matters specifically
 * for Google Meet: Meet writes its recording into Drive already, so
 * when Drive is also SIRI's storage the recording is COPIED
 * server-side instead of being dragged down to this host and pushed
 * back up. That optimization exists only while source and destination
 * coincide — point storage at S3 and it simply stops applying.
 *
 * Visibility: files are created with Drive's default permissions —
 * visible to the owning platform account and nothing else. This class
 * never sets an "anyone with the link" permission, never requests a
 * webContentLink, and never issues a shareable URL. Student and
 * instructor access is decided by RecordingPolicy and served by the
 * application, so Drive is storage, never the authorization layer.
 */
final class GoogleDriveRecordingStorage implements RecordingStorage, SupportsNativeIngestion
{
    public const string KEY = 'google_drive';

    public function __construct(
        private readonly GoogleDriveClient $client,
        private readonly MeetingSettings $settings,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function isConfigured(): bool
    {
        return filled($this->settings->recording_drive_root_folder_id)
            && filled($this->settings->platform_meeting_account)
            && $this->settings->decryptedGoogleCredentials() !== null;
    }

    public function put(RecordingStorageRequest $request): StoredRecording
    {
        $target = $this->target();

        try {
            $folderId = $this->resolveFolder($target, $request->partitionedAt);

            $file = $this->client->uploadResumable(
                target: $target,
                parentFolderId: $folderId,
                sourcePath: $request->file->absolutePath,
                filename: $request->displayName,
                mimeType: $request->file->mimeType,
                chunkBytes: (int) config('recordings.google_drive.chunk_bytes', 8 * 1024 * 1024),
            );
        } catch (GatewayRequestException $e) {
            throw $this->translate($e);
        } catch (Throwable $e) {
            throw RecordingStorageException::uploadFailed('Google Drive upload failed.', $e);
        }

        return new StoredRecording(
            locator: new RecordingLocator(self::KEY, $file['id']),
            remoteSizeBytes: $file['size'],
            // Drive reports md5 for ordinary uploaded files; it is
            // recorded but never used as the sole verification signal,
            // since Drive may omit it for some content.
            remoteChecksum: $file['md5Checksum'],
        );
    }

    public function canIngestNatively(NativeRecordingSource $source): bool
    {
        // Same backend, and we are actually able to talk to it. A
        // source from any other driver falls through to streaming.
        return $source->driver === self::KEY
            && $source->reference !== ''
            && $this->isConfigured();
    }

    /**
     * Server-side copy of a Meet-generated recording into SIRI's own
     * Drive area. No bytes traverse this host.
     *
     * Two properties deserve stating outright:
     *
     * COPY, NOT MOVE. Moving would relocate Google's own artifact out
     * from under Meet and change what the meeting organizer sees in
     * their Drive. The original stays put; SIRI takes a copy it owns
     * and controls the retention of.
     *
     * READING THE SOURCE NEEDS ITS OWN SCOPE. A Meet recording is
     * created by Meet, not by this app, so drive.file cannot see it —
     * drive.meet.readonly is what makes the source readable. If that
     * grant is missing, Drive answers 403 and this method reports the
     * copy as unavailable rather than as a failure, so ingestion
     * silently falls back to streaming (which needs the same read
     * permission, and will then fail loudly and correctly).
     */
    public function ingestNatively(NativeIngestionRequest $request): StoredRecording
    {
        $target = $this->target();

        try {
            $folderId = $this->resolveFolder($target, $request->partitionedAt);

            $file = $this->client->copyFile(
                target: $target,
                sourceFileId: $request->source->reference,
                parentFolderId: $folderId,
                name: $request->displayName,
            );
        } catch (GatewayRequestException $e) {
            throw $this->translateNativeCopy($e);
        } catch (Throwable $e) {
            throw RecordingStorageException::nativeIngestionUnavailable('Google Drive server-side copy failed.', $e);
        }

        return new StoredRecording(
            locator: new RecordingLocator(self::KEY, $file['id']),
            remoteSizeBytes: $file['size'],
            remoteChecksum: $file['md5Checksum'],
        );
    }

    /**
     * Metadata-only verification — existence, trashed state, and size.
     * The video is never downloaded back to prove it arrived: that
     * would double the transfer cost of every recording for no extra
     * guarantee beyond what Drive's own size accounting gives.
     */
    public function verify(RecordingLocator $locator, int $expectedBytes, ?string $expectedChecksum = null): void
    {
        $target = $this->target();

        try {
            $file = $this->client->getFile($target, $locator->path);
        } catch (GatewayRequestException $e) {
            throw $this->translate($e);
        }

        if ($file === null) {
            throw RecordingStorageException::verificationFailed('Recording is not present in Google Drive.');
        }

        if ($file['trashed'] === true) {
            throw RecordingStorageException::verificationFailed('Recording is in the Google Drive trash.');
        }

        if ($file['size'] !== null && $file['size'] !== $expectedBytes) {
            throw RecordingStorageException::verificationFailed(sprintf(
                'Google Drive reports %d bytes for a recording uploaded as %d bytes.',
                $file['size'],
                $expectedBytes,
            ));
        }
    }

    public function read(RecordingLocator $locator)
    {
        try {
            return $this->client->openReadStream($this->target(), $locator->path);
        } catch (GatewayRequestException $e) {
            throw $this->translate($e);
        }
    }

    public function delete(RecordingLocator $locator): void
    {
        try {
            $this->client->deleteFile($this->target(), $locator->path);
        } catch (GatewayRequestException $e) {
            throw $this->translate($e);
        }
    }

    // ── Internals ──────────────────────────────────────────────────────

    /**
     * SIRI Education Recordings/<year>/<month>/ — a shallow, stable
     * partition so no folder ever holds every recording the platform
     * has made (Drive degrades badly past a few thousand children).
     *
     * Folder ids are cached because resolving them costs a Drive list
     * call per upload otherwise; a cache miss simply re-resolves, and
     * a concurrent creator is handled inside the client. Crucially,
     * NOTHING is ever read back out of this hierarchy: the database is
     * the source of truth for which student, instructor, subject and
     * booking a recording belongs to. The folder names carry no PII by
     * design.
     */
    private function resolveFolder(GoogleDriveTarget $target, CarbonImmutable $partitionedAt): string
    {
        $root = (string) $this->settings->recording_drive_root_folder_id;

        $year = $this->cachedFolder($target, $root, $partitionedAt->format('Y'));

        return $this->cachedFolder($target, $year, $partitionedAt->format('m'));
    }

    /**
     * A copy that is refused — for permission reasons, or because the
     * source cannot be duplicated — is reported as "fall back to
     * streaming", never as a recording failure. Genuine infrastructure
     * problems (auth, quota) keep their real codes, because streaming
     * would hit exactly the same wall.
     */
    private function translateNativeCopy(GatewayRequestException $e): RecordingStorageException
    {
        $translated = $this->translate($e);

        return match ($translated->failureCode) {
            RecordingFailureCode::StorageAuthFailed,
            RecordingFailureCode::StorageQuotaExceeded => $translated,
            default => RecordingStorageException::nativeIngestionUnavailable($e->getMessage(), $e),
        };
    }

    private function cachedFolder(GoogleDriveTarget $target, string $parentId, string $name): string
    {
        return Cache::remember(
            sprintf('recordings:drive:folder:%s:%s', $parentId, $name),
            now()->addDay(),
            fn (): string => $this->client->resolveOrCreateFolder($target, $parentId, $name),
        );
    }

    private function target(): GoogleDriveTarget
    {
        if (! $this->isConfigured()) {
            throw RecordingStorageException::notConfigured(self::KEY);
        }

        return new GoogleDriveTarget(
            credentialsJson: (string) $this->settings->decryptedGoogleCredentials(),
            delegatedSubject: (string) $this->settings->platform_meeting_account,
            sharedDriveId: $this->settings->recording_drive_shared_drive_id,
        );
    }

    /**
     * Maps a (already sanitized, credential-free) gateway error onto
     * the domain's failure vocabulary, so the retry decision is made
     * from an enum rather than by pattern-matching vendor text
     * anywhere upstream. This is the only place Google's error
     * wording is interpreted.
     */
    private function translate(GatewayRequestException $e): RecordingStorageException
    {
        $message = strtolower($e->getMessage());

        $code = match (true) {
            str_contains($message, 'storagequotaexceeded'),
            str_contains($message, 'quota') && str_contains($message, 'exceed') => RecordingFailureCode::StorageQuotaExceeded,

            str_contains($message, 'unauthorized_client'),
            str_contains($message, 'token error'),
            str_contains($message, 'invalid_grant'),
            str_contains($message, 'insufficient'),
            str_contains($message, 'http 401'),
            str_contains($message, 'http 403') => RecordingFailureCode::StorageAuthFailed,

            default => RecordingFailureCode::StorageUploadFailed,
        };

        return new RecordingStorageException($code, $e->getMessage(), $e);
    }
}
