<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Booking\Contracts\RecordingStorage;
use App\Booking\DTOs\RecordingByteRange;
use App\Booking\DTOs\RecordingLocator;
use App\Booking\DTOs\RecordingStorageRequest;
use App\Booking\DTOs\StoredRecording;
use App\Booking\Exceptions\RecordingStorageException;
use Illuminate\Support\Str;

/**
 * A complete RecordingStorage backed by nothing but an array.
 *
 * Its existence is the portability proof: this file imports no Google
 * type, no AWS type, no Flysystem type, and the entire recording
 * domain — ingestion, verification, retention, delivery — runs against
 * it unchanged. If a future backend could not be substituted this
 * cleanly, the abstraction would not be real.
 */
final class InMemoryRecordingStorage implements RecordingStorage
{
    public const string KEY = 'in_memory';

    /** @var array<string, array{bytes: string, name: string}> */
    public array $objects = [];

    /** @var list<string> */
    public array $deleted = [];

    public bool $configured = true;

    public ?RecordingStorageException $failNextPut = null;

    public ?RecordingStorageException $failNextVerify = null;

    /** Simulates a backend that cannot delete — proves retention never claims a deletion it did not make. */
    public ?RecordingStorageException $failDelete = null;

    /** Simulates a backend that silently truncated the upload. */
    public bool $reportWrongSize = false;

    public function key(): string
    {
        return self::KEY;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function put(RecordingStorageRequest $request): StoredRecording
    {
        if ($this->failNextPut !== null) {
            $failure = $this->failNextPut;
            $this->failNextPut = null;

            throw $failure;
        }

        $id = 'obj-'.Str::random(12);
        $this->objects[$id] = [
            'bytes' => (string) file_get_contents($request->file->absolutePath),
            'name' => $request->displayName,
        ];

        return new StoredRecording(
            locator: new RecordingLocator(self::KEY, $id),
            remoteSizeBytes: strlen($this->objects[$id]['bytes']),
        );
    }

    public function verify(RecordingLocator $locator, int $expectedBytes, ?string $expectedChecksum = null): void
    {
        if ($this->failNextVerify !== null) {
            $failure = $this->failNextVerify;
            $this->failNextVerify = null;

            throw $failure;
        }

        if (! isset($this->objects[$locator->path])) {
            throw RecordingStorageException::verificationFailed('Object missing.');
        }

        $actual = $this->reportWrongSize ? $expectedBytes + 1 : strlen($this->objects[$locator->path]['bytes']);

        if ($actual !== $expectedBytes) {
            throw RecordingStorageException::verificationFailed('Size mismatch.');
        }
    }

    /** @var list<?RecordingByteRange> */
    public array $readRanges = [];

    public function read(RecordingLocator $locator, ?RecordingByteRange $range = null)
    {
        if (! isset($this->objects[$locator->path])) {
            throw RecordingStorageException::verificationFailed('Object missing.');
        }

        $this->readRanges[] = $range;

        $bytes = $this->objects[$locator->path]['bytes'];

        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $range === null ? $bytes : substr($bytes, $range->start, $range->length()));
        rewind($stream);

        return $stream;
    }

    public function delete(RecordingLocator $locator): void
    {
        if ($this->failDelete !== null) {
            throw $this->failDelete;
        }

        unset($this->objects[$locator->path]);
        $this->deleted[] = $locator->path;
    }

    public function storedName(string $objectId): ?string
    {
        return $this->objects[$objectId]['name'] ?? null;
    }
}
