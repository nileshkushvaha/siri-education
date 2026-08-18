<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Booking\Contracts\GoogleDriveClient;
use App\Booking\DTOs\GoogleDriveTarget;
use App\Booking\Exceptions\GatewayRequestException;

/**
 * Records what the adapter asked Drive to do. Deliberately not a
 * mock: the assertions here are about the SHAPE of the Drive
 * interaction (which folder, which name, chunked or not), which reads
 * far better as recorded calls than as expectation chains.
 */
final class FakeGoogleDriveClient implements GoogleDriveClient
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<array{parent: string, name: string}> */
    public array $folderLookups = [];

    /** @var list<array{parent: string, filename: string, chunkBytes: int, size: int}> */
    public array $uploads = [];

    /** @var array<string, array{id: string, size: ?int, mimeType: ?string, md5Checksum: ?string, trashed: bool}> */
    public array $files = [];

    /** @var list<string> */
    public array $deleted = [];

    public ?GoogleDriveTarget $lastTarget = null;

    public ?GatewayRequestException $throwOnUpload = null;

    public ?GatewayRequestException $throwOnCopy = null;

    /** Server-side copies this fake performed: source, destination folder, and name. */
    public array $copies = [];

    /** Byte sizes the fake reports for source files being copied. */
    public array $sourceSizes = [];

    /** Bytes openReadStream() hands back, so the streaming fallback can be asserted end to end. */
    public string $downloadBytes = 'recording bytes';

    public function requestedScopes(): array
    {
        return ['https://www.googleapis.com/auth/drive.file'];
    }

    public function verifyTokenAcquisition(string $credentialsJson, string $delegatedSubject): void
    {
        $this->calls[] = 'verifyTokenAcquisition';
    }

    public function resolveOrCreateFolder(GoogleDriveTarget $target, string $parentId, string $name): string
    {
        $this->calls[] = 'resolveOrCreateFolder';
        $this->lastTarget = $target;
        $this->folderLookups[] = ['parent' => $parentId, 'name' => $name];

        return 'folder:'.$name;
    }

    public function uploadResumable(
        GoogleDriveTarget $target,
        string $parentFolderId,
        string $sourcePath,
        string $filename,
        string $mimeType,
        int $chunkBytes,
    ): array {
        $this->calls[] = 'uploadResumable';
        $this->lastTarget = $target;

        if ($this->throwOnUpload !== null) {
            throw $this->throwOnUpload;
        }

        $size = (int) filesize($sourcePath);
        $this->uploads[] = compact('filename', 'chunkBytes', 'size') + ['parent' => $parentFolderId];

        $this->files['file-1'] = [
            'id' => 'file-1',
            'size' => $size,
            'mimeType' => $mimeType,
            'md5Checksum' => md5_file($sourcePath),
            'trashed' => false,
        ];

        return ['id' => 'file-1', 'size' => $size, 'mimeType' => $mimeType, 'md5Checksum' => md5_file($sourcePath)];
    }

    public function getFile(GoogleDriveTarget $target, string $fileId): ?array
    {
        $this->calls[] = 'getFile';
        $this->lastTarget = $target;

        return $this->files[$fileId] ?? null;
    }

    public function openReadStream(GoogleDriveTarget $target, string $fileId)
    {
        $this->calls[] = 'openReadStream';

        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $this->downloadBytes);
        rewind($stream);

        return $stream;
    }

    public function copyFile(
        GoogleDriveTarget $target,
        string $sourceFileId,
        string $parentFolderId,
        string $name,
    ): array {
        $this->calls[] = 'copyFile';
        $this->lastTarget = $target;

        if ($this->throwOnCopy !== null) {
            throw $this->throwOnCopy;
        }

        $size = $this->sourceSizes[$sourceFileId] ?? 4096;
        $id = 'copy-'.(count($this->copies) + 1);

        $this->copies[] = [
            'source' => $sourceFileId,
            'parent' => $parentFolderId,
            'name' => $name,
            'id' => $id,
        ];

        $this->files[$id] = [
            'id' => $id,
            'size' => $size,
            'mimeType' => 'video/mp4',
            'md5Checksum' => md5($sourceFileId),
            'trashed' => false,
        ];

        return ['id' => $id, 'name' => $name, 'size' => $size, 'mimeType' => 'video/mp4', 'md5Checksum' => md5($sourceFileId)];
    }

    public function deleteFile(GoogleDriveTarget $target, string $fileId): void
    {
        $this->calls[] = 'deleteFile';
        $this->deleted[] = $fileId;
        unset($this->files[$fileId]);
    }
}
