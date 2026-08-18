<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\DTOs\StagedRecordingFile;
use App\Booking\Enums\RecordingFailureCode;
use App\Booking\Exceptions\RecordingIngestionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The one controlled place a provider recording may land on local disk
 * between download and upload: a PRIVATE directory
 * (storage/app/private/recording-ingestion by default), never the
 * public disk, never the system temp directory.
 *
 * Everything staged here is transient. The ingestion pipeline deletes
 * each file in a finally-block, and purgeStale() backstops a hard
 * crash (kill -9, OOM) that skipped that block — so a failed transfer
 * can never silently accumulate gigabytes of orphaned video.
 *
 * Deleting on failure is safe because a retry always re-downloads from
 * the provider: staged bytes are never the only copy of anything.
 */
final class RecordingStagingArea
{
    public function __construct(
        private readonly ?string $root = null,
    ) {}

    /** Absolute path of the staging directory, created on first use. */
    public function path(string $relative = ''): string
    {
        $root = $this->root ?? storage_path(
            'app/private/'.trim((string) config('recordings.staging.directory', 'recording-ingestion'), '/')
        );

        if (! is_dir($root)) {
            File::ensureDirectoryExists($root, 0700);
        }

        return $relative === '' ? $root : $root.DIRECTORY_SEPARATOR.ltrim($relative, '/');
    }

    /**
     * Streams a provider response into a staged file without ever
     * holding it in memory. $writer receives an open write handle and
     * is responsible only for pumping bytes into it; enforcement of
     * the size ceiling happens here, not in each provider adapter.
     *
     * @param  callable(resource): void  $writer
     *
     * @throws RecordingIngestionException when the source exceeds the configured ceiling
     */
    public function stageStream(callable $writer, string $filename, string $mimeType): StagedRecordingFile
    {
        $path = $this->allocate($filename);
        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open a recording staging file for writing.');
        }

        try {
            $writer($handle);
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($path);

            throw $e;
        }

        fclose($handle);

        return $this->finalize($path, $filename, $mimeType);
    }

    /**
     * Convenience for callers that already hold the bytes (small
     * fixtures, the test/dev fake provider). Real adapters handling
     * real class videos must use stageStream() instead.
     */
    public function stageContents(string $contents, string $filename, string $mimeType = 'video/mp4'): StagedRecordingFile
    {
        return $this->stageStream(
            static function ($handle) use ($contents): void {
                fwrite($handle, $contents);
            },
            $filename,
            $mimeType,
        );
    }

    /** Deletes staged files older than the configured window. Returns how many were removed. */
    public function purgeStale(): int
    {
        $root = $this->path();
        $cutoff = now()->subHours(max(1, (int) config('recordings.staging.stale_hours', 24)))->getTimestamp();
        $purged = 0;

        foreach (File::files($root) as $file) {
            if ($file->getMTime() < $cutoff) {
                @unlink($file->getPathname());
                $purged++;
            }
        }

        return $purged;
    }

    /**
     * Extension for a stored recording, derived from the DETECTED mime
     * type first and only then from the provider's filename — a
     * provider-supplied name is never trusted to shape a path.
     */
    public static function extensionFor(string $mimeType, string $filename): string
    {
        $byMime = match ($mimeType) {
            'video/mp4', 'audio/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            default => null,
        };

        if ($byMime !== null) {
            return $byMime;
        }

        $fromName = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{2,5}$/', $fromName) === 1 ? $fromName : 'bin';
    }

    /**
     * A random, non-guessable staged filename. The provider's own
     * filename is never used on disk: it is untrusted input and could
     * otherwise carry traversal sequences or PII.
     */
    private function allocate(string $filename): string
    {
        $extension = self::extensionFor('', $filename);

        return $this->path(Str::uuid()->toString().'.'.$extension.'.part');
    }

    /**
     * Hashes and measures the staged file, enforces the hard size
     * ceiling, and drops the .part suffix. The mime type is
     * re-detected from the bytes on disk — a provider's declared
     * content type is a hint, never the stored truth.
     */
    private function finalize(string $partPath, string $filename, string $declaredMime): StagedRecordingFile
    {
        $size = (int) filesize($partPath);
        $ceiling = max(1, (int) config('recordings.max_source_bytes'));

        if ($size <= 0) {
            @unlink($partPath);

            throw new RecordingIngestionException(
                RecordingFailureCode::SourceRejected,
                'Provider returned an empty recording.',
            );
        }

        if ($size > $ceiling) {
            @unlink($partPath);

            throw new RecordingIngestionException(
                RecordingFailureCode::SourceRejected,
                sprintf('Recording of %d bytes exceeds the configured ceiling of %d bytes.', $size, $ceiling),
            );
        }

        $detected = $this->detectMimeType($partPath) ?? $declaredMime;
        $allowed = (array) config('recordings.allowed_mime_types', []);

        if ($allowed !== [] && ! in_array($detected, $allowed, true)) {
            @unlink($partPath);

            throw new RecordingIngestionException(
                RecordingFailureCode::SourceRejected,
                sprintf('Recording content type [%s] is not an accepted recording format.', $detected),
            );
        }

        $finalPath = substr($partPath, 0, -strlen('.part'));
        rename($partPath, $finalPath);

        return new StagedRecordingFile(
            absolutePath: $finalPath,
            filename: $filename,
            mimeType: $detected,
            sizeBytes: $size,
            checksum: (string) hash_file('sha256', $finalPath),
        );
    }

    private function detectMimeType(string $path): ?string
    {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $detected = @finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($detected) && $detected !== '' ? $detected : null;
    }
}
