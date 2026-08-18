<?php

declare(strict_types=1);

namespace App\Booking\Exceptions;

use App\Booking\Enums\RecordingFailureCode;
use RuntimeException;
use Throwable;

/**
 * The ONLY exception type a RecordingStorage implementation may throw
 * across the abstraction boundary. Every backend-specific error
 * (a Google\Service\Exception, an S3 error, a disk failure) is
 * translated into one of these by the adapter itself, so the domain
 * classifies failures from a stable enum and never inspects a
 * vendor exception class or parses a vendor message.
 *
 * The message is a safe, already-sanitized diagnostic for logs and
 * admins — adapters must not put credentials, tokens, signed URLs, or
 * raw API payloads into it.
 */
final class RecordingStorageException extends RuntimeException
{
    public function __construct(
        public readonly RecordingFailureCode $failureCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(string $driver): self
    {
        return new self(
            RecordingFailureCode::StorageNotConfigured,
            sprintf('Recording storage driver [%s] is not configured.', $driver),
        );
    }

    public static function uploadFailed(string $message, ?Throwable $previous = null): self
    {
        return new self(RecordingFailureCode::StorageUploadFailed, $message, $previous);
    }

    public static function verificationFailed(string $message): self
    {
        return new self(RecordingFailureCode::StorageVerificationFailed, $message);
    }

    public static function authFailed(string $message, ?Throwable $previous = null): self
    {
        return new self(RecordingFailureCode::StorageAuthFailed, $message, $previous);
    }

    /**
     * The backend could not take the source object natively and the
     * caller should fall back to the ordinary streaming pipeline.
     *
     * Deliberately its own factory rather than an upload failure:
     * RecordingIngestionService catches THIS case specifically and
     * retries the same attempt over the staged path, so a Drive
     * server-side copy that turns out not to be permitted degrades to
     * a slower transfer instead of failing the recording.
     */
    public static function nativeIngestionUnavailable(string $message, ?Throwable $previous = null): self
    {
        return new self(RecordingFailureCode::StorageNativeCopyUnavailable, $message, $previous);
    }

    public static function quotaExceeded(string $message, ?Throwable $previous = null): self
    {
        return new self(RecordingFailureCode::StorageQuotaExceeded, $message, $previous);
    }
}
