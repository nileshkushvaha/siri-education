<?php

declare(strict_types=1);

namespace App\Booking\Storage;

use App\Booking\Contracts\RecordingStorage;
use App\Booking\DTOs\RecordingLocator;
use App\Booking\DTOs\RecordingStorageRequest;
use App\Booking\DTOs\StoredRecording;
use App\Booking\Enums\RecordingFailureCode;
use App\Booking\Exceptions\RecordingStorageException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * RecordingStorage over any Laravel filesystem disk.
 *
 * THIS IS THE AMAZON S3 SEAM, and it is why the future migration is a
 * configuration change rather than a rewrite. S3 is a Laravel disk, so
 * moving recordings there is:
 *
 *     RECORDING_STORAGE_DRIVER=filesystem
 *     RECORDING_STORAGE_DISK=s3
 *
 * with no new adapter, no new dependency, and no change to the Lesson,
 * Booking, policy, notification, or delivery code. The same class also
 * backs local private storage today and Storage::fake() in tests,
 * which is what proves the domain has no backend-specific knowledge.
 *
 * Streams throughout: putStream/readStream never materialize a class
 * video in PHP memory.
 */
final class FilesystemRecordingStorage implements RecordingStorage
{
    public const string KEY = 'filesystem';

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * A disk name alone is not "configured": serving recordings from a
     * PUBLIC disk would hand out unauthenticated URLs, so that
     * configuration is treated as no configuration at all.
     */
    public function isConfigured(): bool
    {
        $disk = (string) config('recordings.filesystem.disk');

        if ($disk === '' || $disk === 'public') {
            return false;
        }

        return config('filesystems.disks.'.$disk) !== null;
    }

    public function put(RecordingStorageRequest $request): StoredRecording
    {
        $this->assertConfigured();

        $path = $this->objectPath($request);
        $stream = $request->file->openStream();

        try {
            $written = $this->disk()->writeStream($path, $stream);
        } catch (Throwable $e) {
            throw RecordingStorageException::uploadFailed(
                sprintf('Recording write failed on disk [%s].', $this->diskName()),
                $e,
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written === false) {
            throw RecordingStorageException::uploadFailed(
                sprintf('Recording write returned failure on disk [%s].', $this->diskName()),
            );
        }

        return new StoredRecording(
            locator: new RecordingLocator(self::KEY, $path),
            remoteSizeBytes: $this->sizeOrNull($path),
        );
    }

    public function verify(RecordingLocator $locator, int $expectedBytes, ?string $expectedChecksum = null): void
    {
        $this->assertConfigured();

        if (! $this->disk()->exists($locator->path)) {
            throw RecordingStorageException::verificationFailed('Stored recording object does not exist.');
        }

        $actual = $this->sizeOrNull($locator->path);

        // A disk that cannot report size is not a verification failure —
        // existence is all this backend can honestly attest to.
        if ($actual !== null && $actual !== $expectedBytes) {
            throw RecordingStorageException::verificationFailed(sprintf(
                'Stored recording size mismatch: expected %d bytes, found %d.',
                $expectedBytes,
                $actual,
            ));
        }
    }

    public function read(RecordingLocator $locator)
    {
        $this->assertConfigured();

        try {
            $stream = $this->disk()->readStream($locator->path);
        } catch (Throwable $e) {
            throw new RecordingStorageException(
                RecordingFailureCode::StorageReadFailed,
                'Stored recording could not be opened for reading.',
                $e,
            );
        }

        if (! is_resource($stream)) {
            throw RecordingStorageException::verificationFailed('Stored recording object is no longer readable.');
        }

        return $stream;
    }

    public function delete(RecordingLocator $locator): void
    {
        $this->assertConfigured();

        try {
            // Idempotent by design: an already-absent object is success,
            // so a re-run retention sweep never fails on its own work.
            if ($this->disk()->exists($locator->path)) {
                $this->disk()->delete($locator->path);
            }
        } catch (Throwable $e) {
            throw new RecordingStorageException(
                RecordingFailureCode::StorageReadFailed,
                'Stored recording could not be deleted.',
                $e,
            );
        }
    }

    /**
     * recordings/2026/08/lesson-BK-….mp4 — a date partition so no
     * single directory (or S3 prefix) accumulates every recording the
     * platform has ever made. Nothing reads meaning back out of this
     * path; it is only ever resolved through the stored locator.
     */
    private function objectPath(RecordingStorageRequest $request): string
    {
        return implode('/', [
            trim((string) config('recordings.filesystem.root', 'recordings'), '/'),
            $request->partitionedAt->format('Y'),
            $request->partitionedAt->format('m'),
            $request->displayName,
        ]);
    }

    private function sizeOrNull(string $path): ?int
    {
        try {
            return (int) $this->disk()->size($path);
        } catch (Throwable) {
            return null;
        }
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw RecordingStorageException::notConfigured(self::KEY);
        }
    }

    private function diskName(): string
    {
        return (string) config('recordings.filesystem.disk');
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }
}
