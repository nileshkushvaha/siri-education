<?php

declare(strict_types=1);

namespace App\Booking\Storage;

use App\Booking\Contracts\RecordingStorage;
use App\Booking\Exceptions\RecordingStorageException;
use App\Models\Recording;
use Illuminate\Contracts\Container\Container;

/**
 * Chooses which RecordingStorage a given operation runs against.
 *
 * Two distinct questions, deliberately answered by two methods —
 * this split IS the migration guarantee:
 *
 *   default()          where NEW recordings are written
 *                      (config('recordings.storage_driver'))
 *
 *   forRecording()     where an EXISTING recording actually lives
 *                      (recordings.storage_driver on its own row)
 *
 * Because reads and deletes resolve from the row, flipping the config
 * to s3 keeps every Drive-era recording readable and deletable
 * forever, with no backfill required and no cutover window. A
 * migration job would move objects one at a time and rewrite each
 * row's locator; nothing else in the application would notice.
 *
 * Fails CLOSED: an unconfigured or unknown driver raises at ingestion
 * time. It never prevents the application from booting — recording is
 * an optional feature, and a platform running with recording disabled
 * must not care that Drive credentials are absent.
 */
final class RecordingStorageResolver
{
    public function __construct(
        private readonly Container $container,
    ) {}

    /** The backend new recordings are written to. */
    public function default(): RecordingStorage
    {
        $storage = $this->driver((string) config('recordings.storage_driver', FilesystemRecordingStorage::KEY));

        if (! $storage->isConfigured()) {
            throw RecordingStorageException::notConfigured($storage->key());
        }

        return $storage;
    }

    /**
     * The backend a specific recording's bytes are in — never the
     * configured default, which may since have changed.
     */
    public function forRecording(Recording $recording): RecordingStorage
    {
        if ($recording->storage_driver === null) {
            throw RecordingStorageException::notConfigured('none');
        }

        return $this->driver($recording->storage_driver);
    }

    /** @return list<string> */
    public function availableDrivers(): array
    {
        return array_keys($this->registry());
    }

    private function driver(string $key): RecordingStorage
    {
        $class = $this->registry()[$key] ?? throw RecordingStorageException::notConfigured($key);

        return $this->container->make($class);
    }

    /**
     * The driver map lives in config rather than in a constant so a new
     * backend is one config line, and so a test can substitute a fake
     * implementation without the domain gaining a test-only branch.
     *
     * @return array<string, class-string<RecordingStorage>>
     */
    private function registry(): array
    {
        return (array) config('recordings.drivers', [
            FilesystemRecordingStorage::KEY => FilesystemRecordingStorage::class,
            GoogleDriveRecordingStorage::KEY => GoogleDriveRecordingStorage::class,
        ]);
    }
}
